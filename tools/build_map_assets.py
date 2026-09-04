r"""Build the time-of-day dam renders used by the digital twin stage.

Source: the two reference dashboard renders in `Panoramic 360 fix`. The map
region is cropped out of each render and the baked-in UI (marker chips, search
pill, compass, zoom cluster, bottom pills) is removed with a small
patch-match style fill so the live UI can be drawn on top instead.

The crop is only 1032x845 and the stage is full-bleed, so the canvas is widened
to 16:9 by mirroring the terrain outwards; those margins are sized to the chrome
and therefore stay behind the rail, the header and the summary panel. Both
renders are then run through Real-ESRGAN x4 (tools/upscale.py) and resampled to
the delivery size. Dawn and dusk are graded from the day/night pair.

When `D:\BE Software\Panoramic 360 HD\map\meta.json` exists (built by
tools/build_hd_masters.py) the prepared canvases are loaded from there and this
script only grades and encodes.

Usage:
    python tools/build_map_assets.py               # with super-resolution
    python tools/build_map_assets.py --no-upscale  # plain Lanczos
"""

import json
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter
from scipy.ndimage import gaussian_filter

sys.path.insert(0, str(Path(__file__).resolve().parent))
import upscale  # noqa: E402

SRC_DIR = Path(r"D:\BE Software\Panoramic 360 fix")
OUT_DIR = Path(__file__).resolve().parent.parent / "public" / "assets" / "map"
# HD masters produced once by tools/build_hd_masters.py: already cleaned,
# extended to full-bleed and super-resolved.
MASTER_DIR = Path(r"D:\BE Software\Panoramic 360 HD") / "map"

# Map region inside the 1672x941 reference renders.
# Map region inside the 1672x941 reference renders.
CROP = (196, 96, 1228, 941)

# Baked UI rectangles, in cropped-image coordinates (union of both renders).
UI_RECTS = [
    (18, 18, 102, 112),      # compass
    (388, 20, 556, 72),      # search pill
    (498, 88, 622, 157),     # marker: sideway
    (792, 158, 938, 224),    # marker: awlr sungai
    (258, 222, 392, 294),    # marker: pos hujan
    (822, 282, 1008, 347),   # marker: robotic total station
    (652, 377, 793, 440),    # marker: piezometer
    (822, 452, 1003, 520),   # marker: gps deformasi
    (197, 467, 352, 537),    # marker: intake tower
    (634, 525, 773, 595),    # marker: rembesan hilir
    (947, 620, 1022, 820),   # zoom / recenter / 3D cluster
    (340, 755, 824, 823),    # bottom mode pills
    (0, 585, 58, 845),       # bleed of the system-monitor card
]

OUTPUT_SCALE = 2.5   # crop 1032x845 -> kanvas full-bleed, lalu 2.5x
QUALITY = 90

# Layout chrome in CSS pixels at the design viewport (1600x900): the rail, the
# header, the summary panel and the bottom margin. The cleaned crop fills the
# clear area between them, so the canvas is padded in the same proportions to
# become a full-bleed 16:9 image.
CHROME = {
    'left': 236,
    'right': 460,
    'top': 104,
    'bottom': 64,
    'clear_w': 1600 - 236 - 460,
    'clear_h': 900 - 104 - 64,
}


def rect_mask(shape, rects, pad=0):
    mask = np.zeros(shape[:2], dtype=bool)
    for x0, y0, x1, y1 in rects:
        mask[max(0, y0 - pad):y1 + pad, max(0, x0 - pad):x1 + pad] = True
    return mask


def ring_indices(rect, shape, ring=12):
    x0, y0, x1, y1 = rect
    h, w = shape[:2]
    ox0, oy0 = max(0, x0 - ring), max(0, y0 - ring)
    ox1, oy1 = min(w, x1 + ring), min(h, y1 + ring)
    outer = np.zeros((h, w), dtype=bool)
    outer[oy0:oy1, ox0:ox1] = True
    inner = np.zeros((h, w), dtype=bool)
    inner[y0:y1, x0:x1] = True
    return outer & ~inner


def _search_offset(img, rect, ring_pts, target, blocked, min_disp, max_block, step, span):
    x0, y0, x1, y1 = rect
    h, w = y1 - y0, x1 - x0
    H, W = img.shape[:2]
    best, best_score = None, np.inf
    for dy in range(-int(span * h), int(span * h) + 1, step):
        for dx in range(-int(span * w), int(span * w) + 1, step):
            if abs(dx) < w * min_disp and abs(dy) < h * min_disp:
                continue
            sy0, sx0, sy1, sx1 = y0 + dy, x0 + dx, y1 + dy, x1 + dx
            if sy0 < 0 or sx0 < 0 or sy1 > H or sx1 > W:
                continue
            src_pts = ring_pts + np.array([dy, dx])
            if (src_pts[:, 0] < 0).any() or (src_pts[:, 0] >= H).any():
                continue
            if (src_pts[:, 1] < 0).any() or (src_pts[:, 1] >= W).any():
                continue
            if blocked[sy0:sy1, sx0:sx1].mean() > max_block:
                continue
            cand = img[src_pts[:, 0], src_pts[:, 1]].astype(np.float32)
            score = float(np.mean((cand - target) ** 2))
            if score < best_score:
                best, best_score = (dy, dx), score
    return best


def _diffusion_fill(img, rect, iterations=260):
    """Last resort: grow the surrounding pixels inwards, then re-grain."""
    x0, y0, x1, y1 = rect
    pad = 14
    H, W = img.shape[:2]
    ax0, ay0 = max(0, x0 - pad), max(0, y0 - pad)
    ax1, ay1 = min(W, x1 + pad), min(H, y1 + pad)
    area = img[ay0:ay1, ax0:ax1].astype(np.float32)
    hole = np.zeros(area.shape[:2], dtype=bool)
    hole[y0 - ay0:y1 - ay0, x0 - ax0:x1 - ax0] = True
    known = ~hole
    area[hole] = area[known].mean(axis=0)
    for _ in range(iterations):
        blur = gaussian_filter(area, sigma=(2.0, 2.0, 0))
        area[hole] = blur[hole]
    rng = np.random.default_rng(7)
    grain = rng.normal(0, float(img[known.shape[0] // 2:, :].std()) * 0.12, area.shape)
    area[hole] += grain[hole]
    img[y0:y1, x0:x1] = np.clip(area[y0 - ay0:y1 - ay0, x0 - ax0:x1 - ax0], 0, 255).astype(np.uint8)


def patch_fill(img, rect, blocked, feather_width=9):
    """Fill `rect` by copying the best matching nearby patch of the image."""
    x0, y0, x1, y1 = rect
    h, w = y1 - y0, x1 - x0

    ring = ring_indices(rect, img.shape, ring=12) & ~blocked
    if ring.sum() < 50:
        ring = ring_indices(rect, img.shape, ring=12)
    ring_pts = np.argwhere(ring)
    target = img[ring_pts[:, 0], ring_pts[:, 1]].astype(np.float32)

    offset = _search_offset(img, rect, ring_pts, target, blocked, 0.55, 0.02, 6, 2.2)
    if offset is None:
        offset = _search_offset(img, rect, ring_pts, target, blocked, 0.4, 0.18, 4, 3.2)
    if offset is None:
        _diffusion_fill(img, rect)
        return

    dy, dx = offset
    patch = img[y0 + dy:y1 + dy, x0 + dx:x1 + dx].astype(np.float32)

    # Feather towards neighbouring pixels, but never towards an image border:
    # there is nothing to blend with there and the artefact would survive.
    H, W = img.shape[:2]
    inset = feather_width
    top = inset if y0 > 0 else 0
    left = inset if x0 > 0 else 0
    bottom = h - inset if y1 < H else h
    right = w - inset if x1 < W else w
    feather = np.zeros((h, w), dtype=np.float32)
    feather[top:bottom, left:right] = 1.0
    feather = gaussian_filter(feather, sigma=max(4.5, feather_width * 0.55))
    if y0 == 0:
        feather[:inset, :] = feather[inset:inset + 1, :]
    if x0 == 0:
        feather[:, :inset] = feather[:, inset:inset + 1]
    if y1 >= H:
        feather[-inset:, :] = feather[-inset - 1:-inset, :]
    if x1 >= W:
        feather[:, -inset:] = feather[:, -inset - 1:-inset]
    feather = np.clip(feather, 0, 1)[..., None]

    dest = img[y0:y1, x0:x1].astype(np.float32)
    img[y0:y1, x0:x1] = np.clip(patch * feather + dest * (1 - feather), 0, 255).astype(np.uint8)


def clean_render(path):
    """Strip the baked dashboard UI out of a reference render."""
    im = Image.open(path).convert("RGB").crop(CROP)
    arr = np.asarray(im).copy()
    h, w = arr.shape[:2]
    grow = 8  # the two renders place their chips a few pixels apart
    rects = [(max(0, x0 - grow), max(0, y0 - grow), min(w, x1 + grow), min(h, y1 + grow))
             for x0, y0, x1, y1 in UI_RECTS]
    blocked = rect_mask(arr.shape, rects, pad=4)
    # Largest rectangles first: they benefit most from untouched surroundings.
    for rect in sorted(rects, key=lambda r: -((r[2] - r[0]) * (r[3] - r[1]))):
        patch_fill(arr, rect, blocked)
        # a cleaned rectangle is fair game as a source for the next one
        blocked[rect[1]:rect[3], rect[0]:rect[2]] = False

    return arr.astype(np.float32)


def extend_canvas(arr):
    """Grow the cleaned crop into a full-bleed 16:9 canvas.

    The reference render only holds usable pixels between the floating panels,
    so the stage would otherwise show the edges of the crop. The margins that
    always sit behind the rail, the header and the summary panel are filled by
    mirroring the terrain outwards — reflection keeps the seam continuous, and
    the invented strips are darkened slightly because glass sits on top of them.

    Returns (canvas, content_inset) where the inset is the fractional position
    of the real crop inside the canvas; the frontend needs it to place markers.
    """
    height, width = arr.shape[:2]

    # Fractions of the viewport taken up by the chrome (see the Blade layout).
    left = round(width * CHROME["left"] / CHROME["clear_w"])
    right = round(width * CHROME["right"] / CHROME["clear_w"])
    top = round(height * CHROME["top"] / CHROME["clear_h"])
    bottom = round(height * CHROME["bottom"] / CHROME["clear_h"])

    canvas = np.zeros((height + top + bottom, width + left + right, 3), dtype=np.float32)
    canvas[top:top + height, left:left + width] = arr

    def mirror_h(source, size, flip_from_left):
        strip = source[:, :size] if flip_from_left else source[:, -size:]
        return strip[:, ::-1]

    if left:
        canvas[top:top + height, :left] = mirror_h(arr, left, True)
    if right:
        canvas[top:top + height, left + width:] = mirror_h(arr, right, False)

    filled = canvas[top:top + height]
    if top:
        canvas[:top] = filled[:top][::-1]
    if bottom:
        canvas[top + height:] = filled[-bottom:][::-1]

    # Push the invented margins back: soften and darken towards the edges.
    inner = (left, top, left + width, top + height)
    mask = np.ones(canvas.shape[:2], dtype=np.float32)
    mask[inner[1]:inner[3], inner[0]:inner[2]] = 0.0
    mask = np.clip(gaussian_filter(mask, sigma=18), 0, 1)[..., None]

    blurred = gaussian_filter(canvas, sigma=(3.0, 3.0, 0))
    canvas = canvas * (1 - mask) + blurred * mask * 0.82 + canvas * mask * 0.18

    content_inset = {
        "left": round(left / canvas.shape[1], 6),
        "right": round(right / canvas.shape[1], 6),
        "top": round(top / canvas.shape[0], 6),
        "bottom": round(bottom / canvas.shape[0], 6),
    }

    return np.clip(canvas, 0, 255), content_inset


def grade(arr, mul=(1.0, 1.0, 1.0), gain=1.0, lift=(0.0, 0.0, 0.0), contrast=1.0):
    out = arr * np.array(mul, dtype=np.float32) * gain + np.array(lift, dtype=np.float32)
    if contrast != 1.0:
        out = (out - 128.0) * contrast + 128.0
    return np.clip(out, 0, 255)


def sun_glow(shape, cx, cy, radius, color, strength):
    h, w = shape[:2]
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    d = np.sqrt(((xx - cx * w) / (radius * w)) ** 2 + ((yy - cy * h) / (radius * w)) ** 2)
    g = np.clip(1.0 - d, 0, 1) ** 2.2
    return g[..., None] * np.array(color, dtype=np.float32) * strength


def enlarge(arr, use_upscale):
    """Bring a cleaned crop up to the delivery resolution."""
    image = Image.fromarray(np.clip(arr, 0, 255).astype(np.uint8))
    target = (int(image.width * OUTPUT_SCALE), int(image.height * OUTPUT_SCALE))

    if use_upscale:
        image = upscale.upscale(image, wrap=False).resize(target, Image.LANCZOS)
        image = image.filter(ImageFilter.UnsharpMask(radius=1.1, percent=20, threshold=4))
    else:
        image = image.resize(target, Image.LANCZOS)
        image = image.filter(ImageFilter.UnsharpMask(radius=1.6, percent=48, threshold=3))

    return np.asarray(image, dtype=np.float32)


def save(arr, name):
    im = Image.fromarray(np.clip(arr, 0, 255).astype(np.uint8))
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    im.save(OUT_DIR / f"{name}.webp", quality=QUALITY, method=5)
    size_kb = (OUT_DIR / f"{name}.webp").stat().st_size / 1024
    print(f"  {name}.webp  {im.width}x{im.height}  {size_kb:6.0f} KB")


def main():
    use_upscale = "--no-upscale" not in sys.argv and upscale.available()

    if not use_upscale:
        print("  Super-resolution dilewati (pakai Lanczos).")

    master_meta = MASTER_DIR / "meta.json"

    if master_meta.exists():
        print(f"memakai master HD dari {MASTER_DIR}")
        inset = json.loads(master_meta.read_text(encoding="utf-8"))["content"]
        day = np.asarray(Image.open(MASTER_DIR / "day.jpg").convert("RGB"), dtype=np.float32)
        night = np.asarray(Image.open(MASTER_DIR / "night.jpg").convert("RGB"), dtype=np.float32)
    else:
        print("cleaning day render")
        day = clean_render(SRC_DIR / "Example_Dashboard_At_Daylight.png")
        print("cleaning night render")
        night = clean_render(SRC_DIR / "Example_Dashboard_At_NIGHT.png")

        print("extending to a full-bleed canvas")
        day, inset = extend_canvas(day)
        night, _ = extend_canvas(night)

        print("upscaling")
        day = enlarge(day, use_upscale)
        night = enlarge(night, use_upscale)

    # Dawn: mostly night, cool blue base with a warm rim from the east (left).
    dawn = night * 0.55 + day * 0.45
    dawn = grade(dawn, mul=(1.04, 1.00, 1.06), gain=0.94, contrast=0.96)
    dawn = dawn + sun_glow(dawn.shape, 0.18, 0.14, 0.55, (255, 176, 116), 0.30)

    # Dusk: mostly day, pushed warm/orange, sun low in the west (right).
    dusk = day * 0.62 + night * 0.38
    dusk = grade(dusk, mul=(1.12, 0.98, 0.86), gain=0.9, contrast=1.03)
    dusk = dusk + sun_glow(dusk.shape, 0.86, 0.10, 0.6, (255, 148, 74), 0.34)

    print("writing assets")
    save(night, "map-night")
    save(dawn, "map-dawn")
    save(day, "map-day")
    save(dusk, "map-dusk")

    print()
    print("Perbarui config/dam.php -> 'map' dengan nilai berikut:")
    print(f"    'width' => {day.shape[1]},")
    print(f"    'height' => {day.shape[0]},")
    print("    'content' => [")
    for key, value in inset.items():
        print(f"        '{key}' => {value},")
    print("    ],")


if __name__ == "__main__":
    main()
