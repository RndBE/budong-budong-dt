r"""Build web-ready equirectangular textures from the drone panoramas.

Source files live in `Panoramic 360 fix`. Most exports are only 1774x887, which
a 360 viewer magnifies heavily, so each panorama is run through Real-ESRGAN x4
(see tools/upscale.py) and then resampled down to the delivery size. The seam is
kept continuous by wrapping the image before super-resolution.

`Panoramic_Base Dam.png` is already 7096x3548 of real detail, so it skips
super-resolution and is only resampled.

When `D:\BE Software\Panoramic 360 HD\panorama\<code>.jpg` exists (built by
tools/build_hd_masters.py) it is used as the source instead, so no GPU work is
needed here.

Three tiers are written per station:
  public/assets/panorama/<code>.webp          full sphere texture (HD)
  public/assets/panorama/preview/<code>.webp  low-res, shown while the HD loads
  public/assets/panorama/thumb/<code>.webp    card thumbnail

Usage:
    python tools/build_panorama_assets.py                 # all stations
    python tools/build_panorama_assets.py awlr-hulu ...    # only these codes
    python tools/build_panorama_assets.py --no-upscale     # plain Lanczos
"""

import sys
from pathlib import Path

from PIL import Image, ImageFilter

sys.path.insert(0, str(Path(__file__).resolve().parent))
import upscale  # noqa: E402

Image.MAX_IMAGE_PIXELS = None

SRC_DIR = Path(r"D:\BE Software\Panoramic 360 fix")
# HD masters produced once by tools/build_hd_masters.py; when present the
# super-resolution step is already baked in and this script only encodes.
MASTER_DIR = Path(r"D:\BE Software\Panoramic 360 HD") / "panorama"
OUT_DIR = Path(__file__).resolve().parent.parent / "public" / "assets" / "panorama"

# Delivery widths (height is always half: strict 2:1 equirectangular).
# The sources are 1774px wide; four-times upscaling invents everything past
# ~4096, so a wider delivery only stores upscaler noise at twice the bytes.
HD_WIDTH = 4096
NATIVE_HD_WIDTH = 4096
PREVIEW_WIDTH = 2048   # the sphere is shown at this size while the HD loads
THUMB = (560, 280)

QUALITY_HD = 82
QUALITY_PREVIEW = 72
QUALITY_THUMB = 80

MAPPING = {
    "Panoramic_Base Dam.png": "base-dam",
    "Panoramic_ADR_1.png": "adr-01",
    "Panoramic_ADR_2.png": "adr-02",
    "Panoramic_AVWR.png": "avwr-01",
    "Panoramic_AWGC.png": "awgc-01",
    "Panoramic_AWLR_AWQR_SEDIMEN.png": "awlr-awqr-sedimen",
    "Panoramic_AWLR_Hilir.png": "awlr-hilir",
    "Panoramic_AWLR_Hulu.png": "awlr-hulu",
    "Panoramic_AWR.png": "awr-01",
    "Panoramic_CCTV_1.png": "cctv-01",
    "Panoramic_CCTV_2.png": "cctv-02",
    "Panoramic_EWS.png": "ews-01",
    "Panoramic_GNSS_TILT.png": "gnss-tilt",
    "Panoramic_OSP_OW.png": "osp-ow",
    "Panoramic_Radio_AP.png": "radio-ap",
    "Panoramic_V Notch.png": "v-notch",
}


def sphere(image: Image.Image, width: int) -> Image.Image:
    """Resample to a strict 2:1 sphere at `width`."""
    return image.resize((width, width // 2), Image.LANCZOS)


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    use_upscale = "--no-upscale" not in sys.argv and upscale.available()

    if MASTER_DIR.exists():
        print(f"  Memakai master HD dari {MASTER_DIR}")
    elif not use_upscale:
        print("  Super-resolution dilewati (pakai Lanczos).")

    (OUT_DIR / "preview").mkdir(parents=True, exist_ok=True)
    (OUT_DIR / "thumb").mkdir(parents=True, exist_ok=True)

    for filename, code in MAPPING.items():
        if args and code not in args:
            continue

        src = SRC_DIR / filename
        if not src.exists():
            print(f"  MISSING {filename}")
            continue

        source = Image.open(src).convert("RGB")
        target = NATIVE_HD_WIDTH if source.width >= NATIVE_HD_WIDTH else HD_WIDTH
        master = MASTER_DIR / f"{code}.jpg"

        if master.exists():
            origin = "master"
            hd = sphere(Image.open(master).convert("RGB"), target)
        elif use_upscale and source.width < NATIVE_HD_WIDTH:
            origin = "upscale"
            # Wrap-aware so the 360 seam stays continuous.
            enlarged = upscale.upscale(source, wrap=True)
            hd = sphere(enlarged, target)
            hd = hd.filter(ImageFilter.UnsharpMask(radius=1.1, percent=22, threshold=4))
        else:
            origin = "lanczos"
            hd = sphere(source, target)

        hd.save(OUT_DIR / f"{code}.webp", quality=QUALITY_HD, method=5)
        sphere(source, PREVIEW_WIDTH).save(
            OUT_DIR / "preview" / f"{code}.webp", quality=QUALITY_PREVIEW, method=5
        )
        source.resize(THUMB, Image.LANCZOS).save(
            OUT_DIR / "thumb" / f"{code}.webp", quality=QUALITY_THUMB, method=5
        )

        size_kb = (OUT_DIR / f"{code}.webp").stat().st_size / 1024
        print(f"  {code:<20} {hd.width}x{hd.height}  {size_kb:6.0f} KB  ({origin})")


if __name__ == "__main__":
    main()
