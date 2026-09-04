"""Real-ESRGAN x4 super-resolution helper used by the asset build scripts.

The drone panoramas ship at 1774x887, which looks soft once a 360 viewer
magnifies them. Plain Lanczos only smooths the existing pixels, so the assets
are instead run through Real-ESRGAN (RRDBNet x4) and then resampled down to the
delivery size — that keeps edges (railings, ladders, sensor housings) crisp.

The network architecture below is the standard BasicSR RRDBNet; weights are the
official `RealESRGAN_x4plus.pth`, downloaded once into tools/models/.

Requires torch. Runs on CUDA when available, otherwise CPU (much slower).
"""

from pathlib import Path

import numpy as np
import torch
import torch.nn as nn
import torch.nn.functional as F
from PIL import Image

WEIGHTS = Path(__file__).resolve().parent / 'models' / 'RealESRGAN_x4plus.pth'
WEIGHTS_URL = 'https://github.com/xinntao/Real-ESRGAN/releases/download/v0.1.0/RealESRGAN_x4plus.pth'


class ResidualDenseBlock(nn.Module):
    def __init__(self, num_feat=64, num_grow_ch=32):
        super().__init__()
        self.conv1 = nn.Conv2d(num_feat, num_grow_ch, 3, 1, 1)
        self.conv2 = nn.Conv2d(num_feat + num_grow_ch, num_grow_ch, 3, 1, 1)
        self.conv3 = nn.Conv2d(num_feat + 2 * num_grow_ch, num_grow_ch, 3, 1, 1)
        self.conv4 = nn.Conv2d(num_feat + 3 * num_grow_ch, num_grow_ch, 3, 1, 1)
        self.conv5 = nn.Conv2d(num_feat + 4 * num_grow_ch, num_feat, 3, 1, 1)
        self.lrelu = nn.LeakyReLU(negative_slope=0.2, inplace=True)

    def forward(self, x):
        x1 = self.lrelu(self.conv1(x))
        x2 = self.lrelu(self.conv2(torch.cat((x, x1), 1)))
        x3 = self.lrelu(self.conv3(torch.cat((x, x1, x2), 1)))
        x4 = self.lrelu(self.conv4(torch.cat((x, x1, x2, x3), 1)))
        x5 = self.conv5(torch.cat((x, x1, x2, x3, x4), 1))

        return x5 * 0.2 + x


class RRDB(nn.Module):
    def __init__(self, num_feat, num_grow_ch=32):
        super().__init__()
        self.rdb1 = ResidualDenseBlock(num_feat, num_grow_ch)
        self.rdb2 = ResidualDenseBlock(num_feat, num_grow_ch)
        self.rdb3 = ResidualDenseBlock(num_feat, num_grow_ch)

    def forward(self, x):
        out = self.rdb3(self.rdb2(self.rdb1(x)))

        return out * 0.2 + x


class RRDBNet(nn.Module):
    def __init__(self, num_in_ch=3, num_out_ch=3, num_feat=64, num_block=23, num_grow_ch=32):
        super().__init__()
        self.conv_first = nn.Conv2d(num_in_ch, num_feat, 3, 1, 1)
        self.body = nn.Sequential(*[RRDB(num_feat, num_grow_ch) for _ in range(num_block)])
        self.conv_body = nn.Conv2d(num_feat, num_feat, 3, 1, 1)
        self.conv_up1 = nn.Conv2d(num_feat, num_feat, 3, 1, 1)
        self.conv_up2 = nn.Conv2d(num_feat, num_feat, 3, 1, 1)
        self.conv_hr = nn.Conv2d(num_feat, num_feat, 3, 1, 1)
        self.conv_last = nn.Conv2d(num_feat, num_out_ch, 3, 1, 1)
        self.lrelu = nn.LeakyReLU(negative_slope=0.2, inplace=True)

    def forward(self, x):
        feat = self.conv_first(x)
        feat = feat + self.conv_body(self.body(feat))
        feat = self.lrelu(self.conv_up1(F.interpolate(feat, scale_factor=2, mode='nearest')))
        feat = self.lrelu(self.conv_up2(F.interpolate(feat, scale_factor=2, mode='nearest')))

        return self.conv_last(self.lrelu(self.conv_hr(feat)))


_model = None
_device = None


def available() -> bool:
    return WEIGHTS.exists()


def load_model():
    """Load the network once per process."""
    global _model, _device

    if _model is not None:
        return _model, _device

    if not WEIGHTS.exists():
        raise FileNotFoundError(
            f'Weights tidak ditemukan di {WEIGHTS}.\nUnduh dari: {WEIGHTS_URL}'
        )

    _device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
    state = torch.load(WEIGHTS, map_location='cpu', weights_only=True)
    state = state.get('params_ema', state.get('params', state))

    model = RRDBNet()
    model.load_state_dict(state, strict=True)
    model.eval().to(_device)

    if _device.type == 'cuda':
        model.half()

    _model = model
    print(f'  Real-ESRGAN x4 dimuat pada {_device.type.upper()}')

    return _model, _device


@torch.inference_mode()
def upscale(image: Image.Image, tile: int = 384, overlap: int = 24, wrap: bool = False) -> Image.Image:
    """4x super-resolution, processed in overlapping tiles to bound VRAM use.

    `wrap=True` pads the image with pixels from the opposite edge first, which
    keeps the 360 seam of an equirectangular panorama continuous.
    """
    model, device = load_model()

    source = image.convert('RGB')
    pad = 0

    if wrap:
        pad = 64
        wide = Image.new('RGB', (source.width + 2 * pad, source.height))
        wide.paste(source, (pad, 0))
        wide.paste(source.crop((source.width - pad, 0, source.width, source.height)), (0, 0))
        wide.paste(source.crop((0, 0, pad, source.height)), (source.width + pad, 0))
        source = wide

    array = np.asarray(source, dtype=np.float32) / 255.0
    height, width = array.shape[:2]
    output = np.zeros((height * 4, width * 4, 3), dtype=np.float32)
    weights = np.zeros((height * 4, width * 4, 1), dtype=np.float32)

    # Feathered tile weights avoid visible seams where tiles meet.
    ramp = np.linspace(0, 1, overlap * 4, dtype=np.float32)

    for top in range(0, height, tile):
        for left in range(0, width, tile):
            y0 = max(0, top - overlap)
            x0 = max(0, left - overlap)
            y1 = min(height, top + tile + overlap)
            x1 = min(width, left + tile + overlap)

            patch = array[y0:y1, x0:x1]
            tensor = torch.from_numpy(patch).permute(2, 0, 1).unsqueeze(0).to(device)
            if device.type == 'cuda':
                tensor = tensor.half()

            result = model(tensor).clamp(0, 1).float().squeeze(0).permute(1, 2, 0).cpu().numpy()

            mask = np.ones(result.shape[:2] + (1,), dtype=np.float32)
            if y0 > 0:
                mask[:overlap * 4] *= ramp[:, None, None]
            if x0 > 0:
                mask[:, :overlap * 4] *= ramp[None, :, None]
            if y1 < height:
                mask[-overlap * 4:] *= ramp[::-1, None, None]
            if x1 < width:
                mask[:, -overlap * 4:] *= ramp[None, ::-1, None]

            output[y0 * 4:y1 * 4, x0 * 4:x1 * 4] += result * mask
            weights[y0 * 4:y1 * 4, x0 * 4:x1 * 4] += mask

    output = np.divide(output, np.maximum(weights, 1e-6))
    upscaled = Image.fromarray((output * 255.0 + 0.5).astype(np.uint8))

    if wrap:
        upscaled = upscaled.crop((pad * 4, 0, upscaled.width - pad * 4, upscaled.height))

    if device.type == 'cuda':
        torch.cuda.empty_cache()

    return upscaled
