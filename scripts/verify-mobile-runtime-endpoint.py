#!/usr/bin/env python3
"""Verify the production API endpoint embedded in a built Android APK."""

from __future__ import annotations

import argparse
import sys
import zipfile
from pathlib import Path

APPROVED_HOST = b"foodex.50sols.com"
FORBIDDEN_HOSTS = (
    b"vanfoodex.50sols.com",
)


def _scan_stream(stream, needles: tuple[bytes, ...]) -> set[bytes]:
    found: set[bytes] = set()
    if not needles:
        return found
    overlap = max(len(value) for value in needles) - 1
    tail = b""
    while True:
        chunk = stream.read(1024 * 1024)
        if not chunk:
            break
        data = tail + chunk
        for needle in needles:
            if needle in data:
                found.add(needle)
        if len(found) == len(needles):
            break
        tail = data[-overlap:] if overlap > 0 else b""
    return found


def verify_apk(apk_path: Path) -> dict[str, list[str]]:
    if not apk_path.is_file() or apk_path.stat().st_size == 0:
        raise ValueError(f"APK is missing or empty: {apk_path}")
    if apk_path.suffix.lower() != ".apk":
        raise ValueError(f"Expected .apk file: {apk_path}")

    needles = (APPROVED_HOST, *FORBIDDEN_HOSTS)
    approved_locations: list[str] = []
    forbidden_locations: list[str] = []

    try:
        with zipfile.ZipFile(apk_path) as archive:
            for info in archive.infolist():
                if info.is_dir():
                    continue
                with archive.open(info, "r") as stream:
                    found = _scan_stream(stream, needles)
                if APPROVED_HOST in found:
                    approved_locations.append(info.filename)
                if any(host in found for host in FORBIDDEN_HOSTS):
                    forbidden_locations.append(info.filename)
    except zipfile.BadZipFile as exc:
        raise ValueError(f"Invalid APK/ZIP: {apk_path}") from exc

    if forbidden_locations:
        locations = ", ".join(sorted(set(forbidden_locations)))
        raise ValueError(
            "Forbidden production host vanfoodex.50sols.com is embedded in APK "
            f"entries: {locations}"
        )
    if not approved_locations:
        raise ValueError(
            "Approved production host foodex.50sols.com was not found in APK binary"
        )

    return {
        "approved_host": [APPROVED_HOST.decode()],
        "approved_locations": sorted(set(approved_locations)),
        "forbidden_locations": [],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("apk", type=Path)
    args = parser.parse_args()
    try:
        result = verify_apk(args.apk)
    except ValueError as exc:
        print(f"FOODEX APK endpoint verification FAILED: {exc}", file=sys.stderr)
        return 1

    print(
        "FOODEX APK endpoint verification PASSED: "
        f"https://{result['approved_host'][0]} "
        f"found in {', '.join(result['approved_locations'])}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
