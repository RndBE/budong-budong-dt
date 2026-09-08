"""Build the browser icons from the ministry logo.

The tab icon is `assets/logopu.png` scaled down, not the file itself: at
598x598 it is sixty-five kilobytes for something drawn at sixteen pixels, and
every page load pays for it.

Run after replacing the logo:

    python tools/build_favicon.py
"""

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "public" / "assets" / "logopu.png"
OUT = ROOT / "public" / "assets" / "icon"

# 32 is what a tab draws; 180 is what iOS keeps for the home screen. An `.ico`
# holding 16 and 32 covers the browsers that still ask for one by that name.
PNG_SIZES = (32, 180)
ICO_SIZES = ((16, 16), (32, 32), (48, 48))


def square(image: Image.Image) -> Image.Image:
    """Pad to a square so nothing is cropped off a non-square logo."""
    if image.width == image.height:
        return image

    side = max(image.width, image.height)
    canvas = Image.new("RGBA", (side, side), (0, 0, 0, 0))
    canvas.paste(image, ((side - image.width) // 2, (side - image.height) // 2))

    return canvas


def main() -> None:
    if not SOURCE.exists():
        raise SystemExit(f"tidak ada berkas sumber: {SOURCE}")

    OUT.mkdir(parents=True, exist_ok=True)
    logo = square(Image.open(SOURCE).convert("RGBA"))

    for size in PNG_SIZES:
        target = OUT / f"logopu-{size}.png"
        logo.resize((size, size), Image.LANCZOS).save(target, optimize=True)
        print(f"{target.relative_to(ROOT)}  {target.stat().st_size:,} bytes")

    ico = ROOT / "public" / "favicon.ico"
    logo.save(ico, sizes=ICO_SIZES)
    print(f"{ico.relative_to(ROOT)}  {ico.stat().st_size:,} bytes")


if __name__ == "__main__":
    main()
