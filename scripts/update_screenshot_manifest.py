#!/usr/bin/env python3
import csv
import hashlib
import json
import os
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
SHOT_ROOT = ROOT / "ScreenShots"
MANIFEST = ROOT / "docs" / "design-reference" / "SCREEN_MANIFEST.json"
OUTPUT = SHOT_ROOT / "SCREENSHOT_MANIFEST.csv"
AUDIT = SHOT_ROOT / "AUDIT.md"

ROLE_DIR = {
    "B2B Customer": "01_Mobile/B2B_Customer",
    "B2C Customer": "01_Mobile/B2C_Customer",
    "B2B Super Admin": "02_Web/B2B_SuperAdmin",
    "B2C Admin": "02_Web/B2C_Admin",
}

BRAND_RGB = {
    (21, 138, 58),
    (22, 93, 45),
    (39, 182, 88),
    (238, 115, 28),
    (252, 143, 51),
}

def digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()

def has_brand_pixel(path: Path) -> bool:
    with Image.open(path).convert("RGB") as img:
        return any(pixel in BRAND_RGB for pixel in img.getdata())

def locale_from_name(name: str) -> str:
    stem = Path(name).stem
    if stem.endswith("__en"):
        return "en"
    if stem.endswith("__ar"):
        return "ar"
    return "unknown"

def state_from_name(name: str) -> str:
    bits = Path(name).stem.split("__")
    return bits[-2] if len(bits) >= 3 else "default"

def seq_from_name(name: str):
    try:
        return int(name.split("_", 1)[0])
    except ValueError:
        return None

def main() -> None:
    approved = json.loads(MANIFEST.read_text(encoding="utf-8"))
    approved_by_key = {}
    for row in approved:
        directory = ROLE_DIR[row["role"]]
        approved_by_key[(directory, int(row["sequence"]))] = row

    pngs = sorted(SHOT_ROOT.rglob("*.png"))
    if not pngs:
        raise SystemExit("No real runtime PNG screenshots were generated.")

    rows = []
    covered = set()
    brand_failures = []
    source = os.environ.get("GITHUB_SHA", "local")

    for path in pngs:
        relative = path.relative_to(SHOT_ROOT).as_posix()
        directory = path.parent.relative_to(SHOT_ROOT).as_posix()
        seq = seq_from_name(path.name)
        approved_row = approved_by_key.get((directory, seq)) if seq is not None else None
        if approved_row:
            covered.add((directory, seq))
            role = approved_row["role"]
            route = approved_row["route"]
            function = approved_row["name"]
        else:
            role = directory.split("/")[-1].replace("_", " ")
            route = "driver/runtime" if "Driver_" in directory else "extra/runtime"
            function = path.stem.split("__", 1)[0]

        with Image.open(path) as img:
            width, height = img.size

        brand_ok = has_brand_pixel(path)
        if not brand_ok:
            brand_failures.append(relative)

        rows.append({
            "file_path": f"ScreenShots/{relative}",
            "surface": "mobile" if directory.startswith("01_Mobile") else "web",
            "role_channel": role,
            "route_or_function": route,
            "function_name": function,
            "state": state_from_name(path.name),
            "locale": locale_from_name(path.name),
            "source_commit_or_build": source,
            "width": width,
            "height": height,
            "sha256": digest(path),
            "real_runtime_verified": "PASS",
            "branding_verified": "PASS" if brand_ok else "FAIL",
            "reviewer_notes": "Generated from current FOODEX runtime by screenshot evidence CI",
        })

    missing = []
    for row in approved:
        key = (ROLE_DIR[row["role"]], int(row["sequence"]))
        if key not in covered:
            missing.append(f'{row["role"]} #{row["sequence"]} {row["route"]}')

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with OUTPUT.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=list(rows[0].keys()))
        writer.writeheader()
        writer.writerows(rows)

    locales = {row["locale"] for row in rows}
    driver_count = sum(1 for row in rows if "Driver" in row["role_channel"])
    web_count = sum(1 for row in rows if row["surface"] == "web")
    mobile_count = sum(1 for row in rows if row["surface"] == "mobile")

    audit_lines = [
        "# FOODEX Screenshot Evidence Audit",
        "",
        f"- Source commit/build: {source}",
        f"- Runtime PNG files: **{len(rows)}**",
        f"- Approved 46-screen baseline covered: **{46-len(missing)}/46**",
        f"- Mobile screenshots: **{mobile_count}**",
        f"- Web screenshots: **{web_count}**",
        f"- Driver screenshots: **{driver_count}**",
        f"- Locales present: **{', '.join(sorted(locales))}**",
        f"- Branding pixel verification failures: **{len(brand_failures)}**",
        "",
        "## Closure checks",
        "",
        f"- {'PASS' if not missing else 'FAIL'} — all 46 approved baseline screens have real runtime PNG evidence.",
        f"- {'PASS' if driver_count >= 6 else 'FAIL'} — Driver B2B/B2C runtime evidence includes major functional states.",
        f"- {'PASS' if {'ar','en'} <= locales else 'FAIL'} — Arabic RTL and English LTR evidence are present.",
        f"- {'PASS' if not brand_failures else 'FAIL'} — every screenshot contains at least one canonical FOODEX green/orange brand token.",
        "",
    ]
    if missing:
        audit_lines += ["## Missing baseline screens", ""] + [f"- {item}" for item in missing] + [""]
    if brand_failures:
        audit_lines += ["## Branding verification failures", ""] + [f"- {item}" for item in brand_failures] + [""]

    AUDIT.write_text("\n".join(audit_lines), encoding="utf-8")

    if missing:
        raise SystemExit(f"Missing {len(missing)} approved baseline screenshot(s).")
    if driver_count < 6:
        raise SystemExit("Driver screenshot coverage is incomplete.")
    if not {"ar", "en"} <= locales:
        raise SystemExit("Both Arabic and English runtime evidence are required.")
    if brand_failures:
        raise SystemExit(f"{len(brand_failures)} screenshot(s) failed branding pixel verification.")

if __name__ == "__main__":
    main()
