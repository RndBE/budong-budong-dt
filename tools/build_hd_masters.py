"""Bake the super-resolution step into re-usable HD master assets.

Running Real-ESRGAN on every asset rebuild needs a GPU and a few minutes. This
script does it once and writes the results next to the original material:

    D:\\BE Software\\Panoramic 360 HD\\
        panorama\\<code>.jpg     5120x2560 (base-dam: 6144x3072)
        map\\day.jpg             4565x2597, UI sudah dibersihkan + full-bleed
        map\\night.jpg
        map\\meta.json           ukuran kanvas + inset area foto
        README.txt

The original files in `Panoramic 360 fix` are never touched. Once the masters
exist, `build_panorama_assets.py` and `build_map_assets.py` pick them up
automatically and only resize/encode, so rebuilding the web assets takes
seconds and needs no GPU. The masters are plain JPEG, so QGIS/Photoshop and the
other dam projects can use them too.

Usage:
    python tools/build_hd_masters.py                 # semua
    python tools/build_hd_masters.py --only=panorama # atau --only=map
    python tools/build_hd_masters.py --force         # tulis ulang yang sudah ada
"""

import json
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_map_assets as maps  # noqa: E402
import build_panorama_assets as panoramas  # noqa: E402
import upscale  # noqa: E402

Image.MAX_IMAGE_PIXELS = None

MASTER_DIR = Path(r"D:\BE Software\Panoramic 360 HD")
JPEG = {"quality": 95, "subsampling": 1, "optimize": True}


def write_jpeg(image: Image.Image, path: Path) -> float:
    path.parent.mkdir(parents=True, exist_ok=True)
    image.save(path, **JPEG)

    return path.stat().st_size / 1048576


def build_panoramas(force: bool):
    out_dir = MASTER_DIR / "panorama"
    print("panorama master")

    for filename, code in panoramas.MAPPING.items():
        src = panoramas.SRC_DIR / filename
        target_path = out_dir / f"{code}.jpg"

        if not src.exists():
            print(f"  MISSING {filename}")
            continue

        if target_path.exists() and not force:
            print(f"  {code:<20} sudah ada, dilewati")
            continue

        source = Image.open(src).convert("RGB")

        if source.width >= panoramas.NATIVE_HD_WIDTH:
            # Real detail already: only normalise to a strict 2:1 sphere.
            master = panoramas.sphere(source, panoramas.NATIVE_HD_WIDTH)
        else:
            enlarged = upscale.upscale(source, wrap=True)
            master = panoramas.sphere(enlarged, panoramas.HD_WIDTH)
            master = master.filter(ImageFilter.UnsharpMask(radius=1.1, percent=22, threshold=4))

        size_mb = write_jpeg(master, target_path)
        print(f"  {code:<20} {master.width}x{master.height}  {size_mb:5.1f} MB")


def build_map(force: bool):
    out_dir = MASTER_DIR / "map"
    print("map master")

    if (out_dir / "meta.json").exists() and not force:
        print("  sudah ada, dilewati")

        return

    print("  membersihkan render siang")
    day = maps.clean_render(maps.SRC_DIR / "Example_Dashboard_At_Daylight.png")
    print("  membersihkan render malam")
    night = maps.clean_render(maps.SRC_DIR / "Example_Dashboard_At_NIGHT.png")

    print("  melebarkan kanvas ke full-bleed")
    day, inset = maps.extend_canvas(day)
    night, _ = maps.extend_canvas(night)

    print("  super-resolution")
    day = maps.enlarge(day, True)
    night = maps.enlarge(night, True)

    day_image = Image.fromarray(np.clip(day, 0, 255).astype(np.uint8))
    night_image = Image.fromarray(np.clip(night, 0, 255).astype(np.uint8))

    size_day = write_jpeg(day_image, out_dir / "day.jpg")
    size_night = write_jpeg(night_image, out_dir / "night.jpg")

    (out_dir / "meta.json").write_text(
        json.dumps(
            {
                "width": day_image.width,
                "height": day_image.height,
                "content": inset,
                "source": "Example_Dashboard_At_Daylight.png / _NIGHT.png",
                "note": "UI bawaan render sudah dihapus; margin di luar 'content' adalah terrain pantulan yang berada di balik panel.",
            },
            indent=4,
        ),
        encoding="utf-8",
    )

    print(f"  day.jpg    {day_image.width}x{day_image.height}  {size_day:5.1f} MB")
    print(f"  night.jpg  {night_image.width}x{night_image.height}  {size_night:5.1f} MB")
    print("  meta.json  ukuran kanvas + inset area foto")


def write_readme():
    (MASTER_DIR / "README.txt").write_text(
        "Master HD Bendungan Budong Budong\n"
        "=================================\n\n"
        "Dibuat oleh budong-budong-dt/tools/build_hd_masters.py dengan\n"
        "Real-ESRGAN x4. Sumbernya folder 'Panoramic 360 fix' (tidak diubah).\n\n"
        "panorama/<kode>.jpg  Panorama equirectangular 2:1 siap pakai (5120x2560,\n"
        "                     base-dam 6144x3072). Bisa dipakai viewer 360 lain.\n"
        "map/day.jpg          Render bendungan full-bleed, UI bawaan sudah dihapus.\n"
        "map/night.jpg        Versi malam dari render yang sama.\n"
        "map/meta.json        Ukuran kanvas + posisi area foto asli di dalamnya.\n\n"
        "Aset web (webp + preview + thumbnail) dibangkitkan dari berkas ini oleh\n"
        "tools/build_panorama_assets.py dan tools/build_map_assets.py.\n",
        encoding="utf-8",
    )


def main():
    only = next((a.split("=", 1)[1] for a in sys.argv[1:] if a.startswith("--only=")), None)
    force = "--force" in sys.argv

    if not upscale.available():
        print("Bobot Real-ESRGAN tidak ada. Lihat bagian Super-resolution di README.")

        return 1

    MASTER_DIR.mkdir(parents=True, exist_ok=True)

    if only in (None, "panorama"):
        build_panoramas(force)

    if only in (None, "map"):
        build_map(force)

    write_readme()
    print(f"\nMaster tersimpan di {MASTER_DIR}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
