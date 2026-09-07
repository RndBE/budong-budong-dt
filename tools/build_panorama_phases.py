r"""Build the four time-of-day spheres for the dam's base panorama.

Sources are the renders in `Panoramic 360 fix\Transitions\Base Dam` — one per
phase, same viewpoint, exported at 1774x887 like the rest of the panoramas. Each
is run through Real-ESRGAN x4 with the seam wrapped, resampled to the delivery
size, and written in the usual three tiers:

    public/assets/panorama/base-dam-<phase>.webp          sphere texture
    public/assets/panorama/preview/base-dam-<phase>.webp  shown while it loads
    public/assets/panorama/thumb/base-dam-<phase>.webp    card thumbnail

Because all four come from the same batch, the stage can cross-fade between them
without the sky or the framing shifting. The upscaled results are cached as HD
masters next to the other ones, so a re-run costs no GPU time.

Usage:
    python tools/build_panorama_phases.py               # all four phases
    python tools/build_panorama_phases.py night dawn    # only these
    python tools/build_panorama_phases.py --force       # ignore the cache
    python tools/build_panorama_phases.py --no-upscale  # plain Lanczos
"""

import sys
from pathlib import Path

from PIL import Image, ImageFilter

sys.path.insert(0, str(Path(__file__).resolve().parent))
import upscale  # noqa: E402

Image.MAX_IMAGE_PIXELS = None

SRC_DIR = Path(r"D:\BE Software\Panoramic 360 fix\Transitions\Base Dam")
MASTER_DIR = Path(r"D:\BE Software\Panoramic 360 HD") / "panorama"
OUT_DIR = Path(__file__).resolve().parent.parent / "public" / "assets" / "panorama"

# Indonesian file names, English phase keys (the ones `config/dam.php` uses).
SOURCES = {
    "dawn": "Panoramic_Base_Dam_Fajar.png",
    "day": "Panoramic_Base_Dam_Siang.png",
    "dusk": "Panoramic_Base_Dam_Senja.png",
    "night": "Panoramic_Base_Dam_Malam.png",
}

HD_WIDTH = 4096
PREVIEW_WIDTH = 2048   # the sphere is shown at this size while the HD loads
THUMB = (560, 280)

QUALITY_HD = 82
QUALITY_PREVIEW = 72
QUALITY_THUMB = 80


def sphere(image: Image.Image, width: int) -> Image.Image:
    """Resample to a strict 2:1 sphere at `width`."""
    return image.resize((width, width // 2), Image.LANCZOS)


def build(phase: str, filename: str, use_upscale: bool, force: bool) -> None:
    source = SRC_DIR / filename

    if not source.exists():
        print(f"  MISSING {filename}")

        return

    master = MASTER_DIR / f"base-dam-{phase}.jpg"
    image = Image.open(source).convert("RGB")

    if master.exists() and not force:
        origin = "master"
        hd = sphere(Image.open(master).convert("RGB"), HD_WIDTH)
    elif use_upscale:
        origin = "upscale"
        # Wrap-aware so the 360 seam stays continuous.
        enlarged = upscale.upscale(image, wrap=True)
        MASTER_DIR.mkdir(parents=True, exist_ok=True)
        enlarged.save(master, quality=95, subsampling=1)
        hd = sphere(enlarged, HD_WIDTH)
        hd = hd.filter(ImageFilter.UnsharpMask(radius=1.1, percent=22, threshold=4))
    else:
        origin = "lanczos"
        hd = sphere(image, HD_WIDTH)
        hd = hd.filter(ImageFilter.UnsharpMask(radius=1.6, percent=45, threshold=3))

    name = f"base-dam-{phase}"
    hd.save(OUT_DIR / f"{name}.webp", quality=QUALITY_HD, method=5)
    sphere(image, PREVIEW_WIDTH).save(
        OUT_DIR / "preview" / f"{name}.webp", quality=QUALITY_PREVIEW, method=5
    )
    image.resize(THUMB, Image.LANCZOS).save(
        OUT_DIR / "thumb" / f"{name}.webp", quality=QUALITY_THUMB, method=5
    )

    size_kb = (OUT_DIR / f"{name}.webp").stat().st_size / 1024
    print(f"  {name:<22} {hd.width}x{hd.height}  {size_kb:6.0f} KB  ({origin})")


def main():
    wanted = [a for a in sys.argv[1:] if not a.startswith("--")] or list(SOURCES)
    force = "--force" in sys.argv
    use_upscale = "--no-upscale" not in sys.argv and upscale.available()

    if not use_upscale:
        print("  Super-resolution dilewati (pakai Lanczos).")

    (OUT_DIR / "preview").mkdir(parents=True, exist_ok=True)
    (OUT_DIR / "thumb").mkdir(parents=True, exist_ok=True)

    for phase in wanted:
        filename = SOURCES.get(phase)

        if not filename:
            print(f"  fase tidak dikenal: {phase}")

            continue

        build(phase, filename, use_upscale, force)


if __name__ == "__main__":
    main()
