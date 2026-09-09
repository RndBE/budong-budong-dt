r"""Build the weather spheres for the dam's base panorama.

The four time-of-day renders in `Transitions\Base Dam` are all under a clear
sky, so `mendung` could only ever be painted *over* them — grey on top of a
picture that still had the sun in it. This builds the renders that answer for
the weather instead, from the same viewpoint as the rest of the batch, so the
stage can cross-fade to one the way it crosses fades between hours:

    public/assets/panorama/base-dam-<sky>.webp          sphere texture
    public/assets/panorama/preview/base-dam-<sky>.webp  shown while it loads
    public/assets/panorama/thumb/base-dam-<sky>.webp    card thumbnail

Same pipeline as `build_panorama_phases.py`: Real-ESRGAN x4 with the seam
wrapped, resampled to the delivery size, cached as an HD master next to the
others so a re-run costs no GPU time.

The names here have to match `config('dam.stage.weather')`, and the stage only
offers one whose file exists — `MonitoringService::basePhases()` omits a
weather name it cannot find rather than aliasing it to daylight.

Usage:
    python tools/build_panorama_weather.py               # every weather render
    python tools/build_panorama_weather.py mendung       # only this one
    python tools/build_panorama_weather.py --force       # ignore the cache
    python tools/build_panorama_weather.py --no-upscale  # plain Lanczos
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

# Indonesian file names, and the keys `config/dam.php` lists under
# `stage.weather` — which are Indonesian too, because they are the sky states
# `SkyState` reports.
SOURCES = {
    "berawan": "Panoramic_Base_Dam_Berawan.png",
    "mendung": "Panoramic_Base_Dam_Mendung.png",
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


def build(sky: str, filename: str, use_upscale: bool, force: bool) -> None:
    source = SRC_DIR / filename

    if not source.exists():
        print(f"  MISSING {filename}")

        return

    master = MASTER_DIR / f"base-dam-{sky}.jpg"
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

    name = f"base-dam-{sky}"
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

    for sky in wanted:
        filename = SOURCES.get(sky)

        if not filename:
            print(f"  cuaca tidak dikenal: {sky}")

            continue

        build(sky, filename, use_upscale, force)


if __name__ == "__main__":
    main()
