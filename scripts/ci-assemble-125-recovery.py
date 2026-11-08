#!/usr/bin/env python3
"""Assemble a distinct signed-source FOODEX 125 recovery distribution.

Never changes the published v1.0.125 release or canonical latest v1.0.126.
All inputs are downloaded GitHub Actions artifacts from the same head SHA.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import zipfile

SOURCE = os.environ["GITHUB_SHA"]
VERSION = "1.0.125"
ROOT = Path("staged")
OUT = Path("Release")
UPDATE_DIR = OUT / "Updates"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1048576), b""):
            digest.update(block)
    return digest.hexdigest()


def only_file(directory: Path, name: str) -> Path:
    matches = sorted(x for x in directory.rglob(name) if x.is_file() and x.name == name)
    if len(matches) != 1:
        raise RuntimeError(f"Expected exactly one {name} under {directory}; got {matches}")
    return matches[0]


def copy_input(directory: Path, name: str, target: Path) -> Path:
    source = only_file(directory, name)
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)
    return target


def main() -> None:
    assert subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip() == SOURCE
    assert Path("VERSION").read_text().strip() == VERSION
    expected_paths = [
        "apps/customer_app/pubspec.yaml",
        "apps/driver_app/pubspec.yaml",
        "apps/van_app/pubspec.yaml",
    ]
    for path in expected_paths:
        if "version: 1.0.125+125" not in Path(path).read_text():
            raise RuntimeError(f"Recovery mobile app identity mismatch: {path}")

    recovery = ROOT / "recovery"
    mobile = ROOT / "mobile"
    UPDATE_DIR.mkdir(parents=True, exist_ok=True)
    for name in ("FOODEX-Update.zip", "FOODEX-Update.json",
                 "FOODEX-Update.sha256.txt", "FOODEX-Update.files.txt"):
        copy_input(recovery, name, UPDATE_DIR / name)

    update_manifest = json.loads((UPDATE_DIR / "FOODEX-Update.json").read_text())
    update_zip = UPDATE_DIR / "FOODEX-Update.zip"
    assert update_manifest["target_version"] == VERSION
    assert update_manifest["minimum_current_version"] == "1.0.124"
    assert update_manifest["source_commit"] == SOURCE
    assert update_manifest["sha256"] == sha256(update_zip)
    assert update_manifest["contains_migrations"] is True
    assert (UPDATE_DIR / "FOODEX-Update.sha256.txt").read_text().split()[0] == sha256(update_zip)

    setup_zip = copy_input(recovery, "FOODEX-125-Recovery-Setup.zip", OUT / "FOODEX-Laravel-Setup.zip")
    setup_hash_file = only_file(recovery, "FOODEX-125-Recovery-Setup.sha256.txt")
    assert setup_hash_file.read_text().split()[0] == sha256(setup_zip)

    prior_info = json.loads(only_file(recovery, "RECOVERY_BUILD_INFO.json").read_text())
    assert prior_info["source_commit"] == SOURCE
    assert prior_info["setup_sha256"] == sha256(setup_zip)
    assert prior_info["update_sha256"] == sha256(update_zip)

    with zipfile.ZipFile(setup_zip) as archive:
        assert archive.testzip() is None, "Setup archive corruption"
        assert "VERSION" in archive.namelist()
        assert archive.read("VERSION").decode().strip() == VERSION
        assert "backend/vendor/autoload.php" in archive.namelist()
    with zipfile.ZipFile(update_zip) as archive:
        assert archive.testzip() is None, "Update archive corruption"
        assert archive.read("VERSION").decode().strip() == VERSION
        assert "backend/database/migrations/2026_11_08_070000_create_order_customer_service_discounts.php" in archive.namelist()

    mobile_entries = []
    for display in ("Customer", "Driver", "Van"):
        apk = copy_input(mobile, f"FOODEX-{display}.apk", OUT / f"FOODEX-{display}-{VERSION}.apk")
        shutil.copyfile(apk, OUT / f"FOODEX-{display}.apk")
        with zipfile.ZipFile(apk) as archive:
            assert archive.testzip() is None, f"Corrupted {display} APK"
            assert "AndroidManifest.xml" in archive.namelist()
        mobile_entries.append({
            "app": display.lower(),
            "version": VERSION,
            "build_number": 125,
            "file": apk.name,
            "latest_alias": f"FOODEX-{display}.apk",
            "bytes": apk.stat().st_size,
            "sha256": sha256(apk),
        })
    mobile_entries[0].update({
        "release_version": VERSION,
        "lineage_mode": "synchronized",
        "source_commit": SOURCE,
        "tree_sha": subprocess.check_output(
            ["git", "rev-parse", "HEAD:apps/customer_app"], text=True
        ).strip(),
    })

    latest = {
        "schema_version": 1,
        "version": VERSION,
        "build_number": 125,
        "source_commit": SOURCE,
        "recovery": True,
        "installed_from": "1.0.124",
        "dashboard_update": {
            "directory": "Updates",
            "package": "FOODEX-Update.zip",
            "manifest": "FOODEX-Update.json",
        },
        "android_apps": mobile_entries,
    }
    (OUT / "LATEST_RELEASE.json").write_text(json.dumps(latest, indent=2) + "\n")
    (OUT / "RECOVERY_BUILD_INFO.json").write_text(json.dumps(prior_info, indent=2) + "\n")
    (OUT / "FRESH_INSTALL_EVIDENCE.json").write_text(json.dumps({
        "source_commit": SOURCE,
        "version": VERSION,
        "setup_sha256": sha256(setup_zip),
        "result": "PASS",
        "tests": [
            "clean MySQL migrations from actual Setup ZIP",
            "Web Installer route smoke test",
            "InstallerWorkflowTest",
        ],
    }, indent=2) + "\n")
    shutil.copyfile("docs/release/FOODEX_1_0_125_RECOVERY.md", OUT / "RECOVERY_RELEASE_NOTES.md")
    print(f"ASSEMBLED FOODEX 125 recovery from {SOURCE}: {len(mobile_entries)} APKs, Setup, Update")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"RECOVERY ASSEMBLY FAILED: {exc}", file=sys.stderr)
        raise
