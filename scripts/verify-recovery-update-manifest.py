#!/usr/bin/env python3
"""Preflight the exact ZIP/JSON pair used by legacy MySQL upgrade acceptance.

This checks checksum and release identity *before* a test install is overlaid.
It does not authenticate a publisher's digital signature and must not be used
as a replacement for the production Admin Update Center's trust validation.
"""
from __future__ import annotations

import argparse
import hashlib
import hmac
import json
from pathlib import Path
import re
import sys
import zipfile


SHA256_HEX = re.compile(r"^[0-9a-f]{64}$")


def verify(
    package: Path,
    manifest_path: Path,
    target_version: str,
    minimum_current_version: str,
) -> None:
    if not package.is_file() or package.stat().st_size == 0:
        raise ValueError("Missing or empty update ZIP.")
    if not zipfile.is_zipfile(package):
        raise ValueError("Update archive is not a valid ZIP.")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError("Update JSON manifest is unavailable or invalid.") from exc

    if not isinstance(manifest, dict) or type(manifest.get("schema_version")) is not int:
        raise ValueError("Update JSON manifest schema is invalid.")
    if manifest["schema_version"] != 1:
        raise ValueError("Unsupported update manifest schema.")
    if manifest.get("available") is not True or manifest.get("requires_full_redeploy") is not False:
        raise ValueError("This release is not a supported ZIP update.")
    if manifest.get("package") != package.name:
        raise ValueError("Update ZIP filename differs from the manifest.")
    if manifest.get("target_version") != target_version:
        raise ValueError("Update target version mismatch.")
    if manifest.get("minimum_current_version") != minimum_current_version:
        raise ValueError("Unsupported installed version for this recovery upgrade.")

    expected_digest = manifest.get("sha256")
    if not isinstance(expected_digest, str) or not SHA256_HEX.fullmatch(expected_digest):
        raise ValueError("Invalid SHA-256 in update manifest.")

    fields = manifest.get("dashboard_fields")
    if not isinstance(fields, dict):
        raise ValueError("Missing Dashboard update metadata.")
    for key in ("target_version", "minimum_current_version", "sha256"):
        if fields.get(key) != manifest.get(key):
            raise ValueError(f"Conflicting Dashboard update metadata: {key}.")

    digest = hashlib.sha256()
    with package.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    if not hmac.compare_digest(digest.hexdigest(), expected_digest):
        raise ValueError("Update ZIP SHA-256 mismatch.")

    print(
        "PASS: checked original update ZIP + JSON, expected target/minimum "
        "versions, schema and SHA-256 before any install overlay."
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", required=True, type=Path)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--target-version", required=True)
    parser.add_argument("--minimum-current-version", required=True)
    args = parser.parse_args()

    try:
        verify(args.package, args.manifest, args.target_version, args.minimum_current_version)
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
