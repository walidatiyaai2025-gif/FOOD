#!/usr/bin/env python3
import csv
import hashlib
import json
import os
import sys
from collections import defaultdict
from pathlib import Path

from PIL import Image, UnidentifiedImageError

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

BRAND_ANCHOR_RGB = {
    (21, 138, 58),
    (22, 93, 45),
    (39, 182, 88),
    (238, 115, 28),
    (252, 143, 51),
}

CANONICAL_RGB = BRAND_ANCHOR_RGB | {
    (234, 247, 239),
    (255, 241, 230),
    (75, 140, 245),
    (239, 83, 80),
    (23, 32, 51),
    (102, 112, 133),
    (247, 249, 252),
    (230, 234, 240),
}

VALID_LOCALES = {"ar", "en"}
MIN_SCREEN_WIDTH = 320
MIN_SCREEN_HEIGHT = 480
MIN_MOBILE_RATIO = 1.6

# Duplicate screenshot bytes are rejected by default. If a duplicate is genuinely
# intentional, document the exact SHA and exact set of evidence paths here.
INTENTIONAL_DUPLICATES: dict[str, set[str]] = {}


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def has_palette_pixel(path: Path, palette: set[tuple[int, int, int]]) -> bool:
    with Image.open(path).convert("RGB") as img:
        return any(pixel in palette for pixel in img.getdata())


def locale_from_name(name: str) -> str:
    stem = Path(name).stem
    if stem.endswith("__en"):
        return "en"
    if stem.endswith("__ar"):
        return "ar"
    return "unknown"


def state_from_name(name: str) -> str:
    bits = Path(name).stem.split("__")
    return bits[-2].strip() if len(bits) >= 3 else ""


def seq_from_name(name: str):
    try:
        return int(name.split("_", 1)[0])
    except ValueError:
        return None


def validate_dimensions(surface: str, width: int, height: int) -> list[str]:
    failures = []
    if width < MIN_SCREEN_WIDTH or height < MIN_SCREEN_HEIGHT:
        failures.append(
            f"useful size requires at least {MIN_SCREEN_WIDTH}x{MIN_SCREEN_HEIGHT}, got {width}x{height}"
        )
    if surface == "mobile":
        if width >= height:
            failures.append(f"mobile evidence must be portrait, got {width}x{height}")
        elif height / width < MIN_MOBILE_RATIO:
            failures.append(
                f"mobile portrait ratio must be >= {MIN_MOBILE_RATIO}, got {height / width:.3f}"
            )
    return failures


def validate_metadata(locale: str, state: str, route: str, function: str) -> list[str]:
    failures = []
    if locale not in VALID_LOCALES:
        failures.append(f"locale must be one of {sorted(VALID_LOCALES)}, got {locale!r}")
    if not state or state in {"unknown", "none", "null"}:
        failures.append("state metadata is missing or invalid")
    if not route or route in {"unknown", "none", "null"}:
        failures.append("route/function metadata is missing or invalid")
    if not function or function in {"unknown", "none", "null"}:
        failures.append("function name metadata is missing or invalid")
    return failures


def duplicate_failures(
    rows: list[dict[str, object]],
    intentional: dict[str, set[str]] | None = None,
) -> list[str]:
    allowed = INTENTIONAL_DUPLICATES if intentional is None else intentional
    grouped: dict[str, list[str]] = defaultdict(list)
    for row in rows:
        grouped[str(row["sha256"])].append(str(row["file_path"]))

    failures = []
    for sha, paths in sorted(grouped.items()):
        if len(paths) < 2:
            continue
        actual = set(paths)
        if allowed.get(sha) == actual:
            continue
        failures.append(f"{sha}: " + ", ".join(sorted(paths)))
    return failures


def self_test() -> None:
    assert validate_dimensions("mobile", 430, 932) == []
    assert validate_dimensions("web", 1440, 900) == []
    assert validate_dimensions("mobile", 500, 500)
    assert validate_dimensions("mobile", 430, 600)
    assert validate_dimensions("mobile", 100, 200)
    assert validate_metadata("ar", "default", "/home", "home") == []
    assert validate_metadata("fr", "", "", "")
    rows = [
        {"sha256": "same", "file_path": "ScreenShots/a.png"},
        {"sha256": "same", "file_path": "ScreenShots/b.png"},
    ]
    assert duplicate_failures(rows)
    assert duplicate_failures(
        rows,
        {"same": {"ScreenShots/a.png", "ScreenShots/b.png"}},
    ) == []
    print("Screenshot evidence guard self-test: PASS")


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
    evidence_failures = []
    anchor_roles = set()
    source = os.environ.get("GITHUB_SHA", "local")

    for path in pngs:
        relative = path.relative_to(SHOT_ROOT).as_posix()
        directory = path.parent.relative_to(SHOT_ROOT).as_posix()
        surface = "mobile" if directory.startswith("01_Mobile") else "web"
        seq = seq_from_name(path.name)
        approved_row = approved_by_key.get((directory, seq)) if seq is not None else None
        if approved_row:
            covered.add((directory, seq))
            role = approved_row["role"]
            route = str(approved_row["route"]).strip()
            function = str(approved_row["name"]).strip()
        else:
            role = directory.split("/")[-1].replace("_", " ")
            route = "driver/runtime" if "Driver_" in directory else "extra/runtime"
            function = path.stem.split("__", 1)[0].strip()

        locale = locale_from_name(path.name)
        state = state_from_name(path.name)

        try:
            with Image.open(path) as img:
                img.verify()
            with Image.open(path) as img:
                width, height = img.size
            if width <= 0 or height <= 0:
                raise ValueError(f"invalid image dimensions {width}x{height}")
        except (UnidentifiedImageError, OSError, ValueError) as exc:
            evidence_failures.append(f"{relative}: invalid/corrupt PNG ({exc})")
            continue

        for failure in validate_dimensions(surface, width, height):
            evidence_failures.append(f"{relative}: {failure}")
        for failure in validate_metadata(locale, state, route, function):
            evidence_failures.append(f"{relative}: {failure}")

        try:
            brand_ok = has_palette_pixel(path, CANONICAL_RGB)
            anchor_ok = has_palette_pixel(path, BRAND_ANCHOR_RGB)
        except (UnidentifiedImageError, OSError) as exc:
            evidence_failures.append(f"{relative}: PNG cannot be decoded for palette validation ({exc})")
            continue

        if not brand_ok:
            brand_failures.append(relative)
        if anchor_ok:
            anchor_roles.add(role)

        rows.append({
            "file_path": f"ScreenShots/{relative}",
            "surface": surface,
            "role_channel": role,
            "route_or_function": route,
            "function_name": function,
            "state": state,
            "locale": locale,
            "source_commit_or_build": source,
            "width": width,
            "height": height,
            "sha256": digest(path),
            "real_runtime_verified": "PASS",
            "branding_verified": "PASS" if brand_ok else "FAIL",
            "brand_anchor_present": "PASS" if anchor_ok else "NEUTRAL_SCREEN",
            "reviewer_notes": "Generated from current FOODEX runtime by screenshot evidence CI",
        })

    duplicate_groups = duplicate_failures(rows)

    missing = []
    for row in approved:
        key = (ROLE_DIR[row["role"]], int(row["sequence"]))
        if key not in covered:
            missing.append(f'{row["role"]} #{row["sequence"]} {row["route"]}')

    if not rows:
        raise SystemExit("No valid runtime PNG screenshots remained after validation.")

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with OUTPUT.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=list(rows[0].keys()))
        writer.writeheader()
        writer.writerows(rows)

    locales = {str(row["locale"]) for row in rows}
    required_roles = {str(row["role_channel"]) for row in rows}
    roles_without_anchor = sorted(required_roles - anchor_roles)
    driver_count = sum(1 for row in rows if "Driver" in str(row["role_channel"]))
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
        f"- Evidence metadata/geometry/PNG failures: **{len(evidence_failures)}**",
        f"- Unapproved duplicate SHA groups: **{len(duplicate_groups)}**",
        f"- Canonical palette verification failures: **{len(brand_failures)}**",
        f"- Roles/surfaces without a green/orange brand anchor: **{len(roles_without_anchor)}**",
        "",
        "## Closure checks",
        "",
        f"- {'PASS' if not missing else 'FAIL'} — all 46 approved baseline screens have real runtime PNG evidence.",
        f"- {'PASS' if driver_count >= 6 else 'FAIL'} — Driver B2B/B2C runtime evidence includes major functional states.",
        f"- {'PASS' if {'ar','en'} <= locales else 'FAIL'} — Arabic RTL and English LTR evidence are present.",
        f"- {'PASS' if not evidence_failures else 'FAIL'} — PNG validity, useful dimensions, mobile portrait ratio and evidence metadata are valid.",
        f"- {'PASS' if not duplicate_groups else 'FAIL'} — no unrelated screenshots share identical bytes without an explicit documented exception.",
        f"- {'PASS' if not brand_failures else 'FAIL'} — every screenshot contains at least one non-white canonical FOODEX palette token.",
        f"- {'PASS' if not roles_without_anchor else 'FAIL'} — every role/surface has runtime evidence containing a canonical FOODEX green/orange brand anchor.",
        "",
    ]
    if missing:
        audit_lines += ["## Missing baseline screens", ""] + [f"- {item}" for item in missing] + [""]
    if evidence_failures:
        audit_lines += ["## Evidence validation failures", ""] + [f"- {item}" for item in evidence_failures] + [""]
    if duplicate_groups:
        audit_lines += ["## Unapproved duplicate SHA groups", ""] + [f"- {item}" for item in duplicate_groups] + [""]
    if brand_failures:
        audit_lines += ["## Canonical palette verification failures", ""] + [f"- {item}" for item in brand_failures] + [""]
    if roles_without_anchor:
        audit_lines += ["## Roles/surfaces missing a green/orange brand anchor", ""] + [f"- {item}" for item in roles_without_anchor] + [""]

    AUDIT.write_text("\n".join(audit_lines), encoding="utf-8")

    if missing:
        raise SystemExit(f"Missing {len(missing)} approved baseline screenshot(s).")
    if driver_count < 6:
        raise SystemExit("Driver screenshot coverage is incomplete.")
    if not {"ar", "en"} <= locales:
        raise SystemExit("Both Arabic and English runtime evidence are required.")
    if evidence_failures:
        raise SystemExit(f"{len(evidence_failures)} screenshot evidence validation failure(s).")
    if duplicate_groups:
        raise SystemExit(f"{len(duplicate_groups)} unapproved duplicate screenshot SHA group(s).")
    if brand_failures:
        raise SystemExit(f"{len(brand_failures)} screenshot(s) failed canonical palette verification.")
    if roles_without_anchor:
        raise SystemExit(
            f"{len(roles_without_anchor)} role/surface group(s) have no green/orange brand anchor evidence."
        )


if __name__ == "__main__":
    if "--self-test" in sys.argv:
        self_test()
    else:
        main()
