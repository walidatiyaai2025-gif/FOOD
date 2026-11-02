#!/usr/bin/env python3
"""Fail-closed APK binary gate for the dedicated FOODEX production store origin.

This gate intentionally does not change the canonical 50sols release verifier.
"""
from __future__ import annotations

import argparse
import sys
import zipfile
from pathlib import Path

EXPECTED_HOST = b"store.foodexeg.com"
LEGACY_HOSTS = (b"foodex.50sols.com", b"vanfoodex.50sols.com")
CHUNK_SIZE = 1024 * 1024


def _find(stream, needles: tuple[bytes, ...]) -> set[bytes]:
    found: set[bytes] = set()
    tail = b""
    overlap = max(map(len, needles)) - 1
    while True:
        chunk = stream.read(CHUNK_SIZE)
        if not chunk:
            break
        data = tail + chunk
        found.update(needle for needle in needles if needle in data)
        tail = data[-overlap:] if overlap else b""
    return found


def verify(apk: Path) -> dict[str, object]:
    if apk.suffix != ".apk" or not apk.is_file() or apk.stat().st_size == 0:
        raise ValueError("A non-empty Android APK is required")
    matched: list[str] = []
    legacy: list[str] = []
    try:
        with zipfile.ZipFile(apk) as archive:
            if archive.testzip() is not None:
                raise ValueError("APK ZIP CRC validation failed")
            for member in archive.infolist():
                if member.is_dir():
                    continue
                with archive.open(member) as stream:
                    found = _find(stream, (EXPECTED_HOST, *LEGACY_HOSTS))
                if EXPECTED_HOST in found:
                    matched.append(member.filename)
                if any(h in found for h in LEGACY_HOSTS):
                    legacy.append(member.filename)
    except zipfile.BadZipFile as exc:
        raise ValueError("Corrupt APK archive") from exc
    if not matched:
        raise ValueError("Production store origin was not embedded in APK: store.foodexeg.com")
    if legacy:
        raise ValueError("Old/demo API origin still embedded in APK entries: " + ", ".join(sorted(set(legacy))))
    return {"api_base_url": "https://store.foodexeg.com", "found_in": sorted(set(matched)), "legacy_origin_entries": []}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("apk", type=Path)
    args = parser.parse_args()
    try:
        print("FOODEX production API check PASSED:", verify(args.apk))
    except ValueError as exc:
        print("FOODEX production API check FAILED:", exc, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
