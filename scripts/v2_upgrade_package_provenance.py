#!/usr/bin/env python3
"""G006: fail-closed integrity check for historical exact Dashboard Update ZIPs.

This tool validates archive/manifest consistency ONLY. It cannot authenticate
a signing key or prove MySQL migrations, backups, rollback or production readiness.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import stat
import sys
import zipfile
from pathlib import Path
from typing import Any


class PackageContractError(ValueError):
    """An update artifact is unsafe, inconsistent, or not the requested edge."""


SHA_PATTERN = re.compile(r"^[a-f0-9]{40}$")
DIGEST_PATTERN = re.compile(r"^[a-f0-9]{64}$")
VERSION_PATTERN = re.compile(r"^\d+\.\d+\.\d+$")


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise PackageContractError(message)


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def _read_json(path: Path) -> dict[str, Any]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise PackageContractError(f"Cannot read update manifest: {path.name}") from exc
    _require(isinstance(data, dict), "Update manifest must be a JSON object.")
    return data


def _member_name(info: zipfile.ZipInfo) -> str:
    name = info.filename
    canonical = name.replace("\\", "/")
    parts = canonical.split("/")
    _require(
        bool(name) and not name.endswith(("/", "\\")) and
        not canonical.startswith("/") and
        not re.match(r"^[A-Za-z]:", canonical) and
        all(part not in ("", ".", "..") for part in parts),
        f"Unsafe archive member path: {name!r}",
    )
    mode = (info.external_attr >> 16) & 0xFFFF
    _require(not stat.S_ISLNK(mode), f"Symlink is not allowed: {name!r}")
    _require(not info.is_dir(), f"Directory entry is not expected: {name!r}")
    _require(
        not any(part == ".env" or part.startswith(".env.") for part in parts)
        and "storage" not in parts[:2]
        and not canonical.startswith(("apps/", "Release/", "docs/", ".github/"))
        and not canonical.startswith("backend/tests/"),
        f"Private/non-runtime member in update: {name!r}",
    )
    return canonical


def validate_package(
    directory: Path,
    *,
    installed_version: str,
    target_version: str,
    expected_source_commit: str | None = None,
) -> dict[str, str | int]:
    """Validate one exact ZIP+manifest pair; never execute or extract it."""
    _require(
        bool(VERSION_PATTERN.fullmatch(installed_version))
        and bool(VERSION_PATTERN.fullmatch(target_version)),
        "Invalid semantic-version edge.",
    )
    _require(
        tuple(map(int, installed_version.split(".")))
        < tuple(map(int, target_version.split("."))),
        "An update must advance the installed version.",
    )
    zip_path = directory / "FOODEX-Update.zip"
    manifest_path = directory / "FOODEX-Update.json"
    checksum_path = directory / "FOODEX-Update.sha256.txt"
    list_path = directory / "FOODEX-Update.files.txt"
    for path in (zip_path, manifest_path, checksum_path, list_path):
        _require(path.is_file(), f"Required artifact missing: {path.name}")
    manifest = _read_json(manifest_path)
    _require(manifest.get("schema_version") == 1, "Unexpected manifest schema.")
    _require(manifest.get("available") is True, "Artifact is not available.")
    _require(manifest.get("package") == zip_path.name, "Manifest package mismatch.")
    _require(
        manifest.get("target_version") == target_version
        and manifest.get("minimum_current_version") == installed_version,
        "Wrong or non-exact upgrade edge.",
    )
    _require(
        manifest.get("requires_full_redeploy") is False,
        "ZIP-only update is not authorized when full redeploy is required.",
    )
    source = manifest.get("source_commit")
    source_base = manifest.get("source_base")
    _require(
        isinstance(source, str) and bool(SHA_PATTERN.fullmatch(source))
        and isinstance(source_base, str) and bool(SHA_PATTERN.fullmatch(source_base)),
        "Missing immutable source/base Git SHA.",
    )
    if expected_source_commit is not None:
        _require(source == expected_source_commit, "Source SHA does not match the tested source.")
    digest = _sha256(zip_path)
    _require(manifest.get("sha256") == digest, "Manifest ZIP SHA-256 mismatch.")
    try:
        checksum = checksum_path.read_text(encoding="utf-8").strip().split()
    except (OSError, UnicodeError) as exc:
        raise PackageContractError("Cannot read ZIP SHA-256 file.") from exc
    _require(
        len(checksum) == 2 and checksum[0] == digest and
        checksum[1] == zip_path.name and bool(DIGEST_PATTERN.fullmatch(checksum[0])),
        "Standalone ZIP SHA-256 checksum does not match the package.",
    )
    try:
        with zipfile.ZipFile(zip_path) as archive:
            names: list[str] = []
            seen: set[str] = set()
            for info in archive.infolist():
                name = _member_name(info)
                _require(name not in seen, f"Duplicate or aliased archive target: {name}")
                seen.add(name)
                names.append(name)
            _require(bool(names) and "VERSION" in seen, "Archive has no VERSION member.")
            _require(
                archive.read("VERSION").decode("utf-8").strip() == target_version,
                "ZIP VERSION differs from target manifest version.",
            )
            bad_member = archive.testzip()
            _require(bad_member is None, f"ZIP member CRC failure: {bad_member}")
    except (OSError, zipfile.BadZipFile, UnicodeError, KeyError) as exc:
        raise PackageContractError("Unreadable or corrupt update ZIP.") from exc
    listed = list_path.read_text(encoding="utf-8").splitlines()
    _require(names == listed, "ZIP member order/list differs from package file list.")
    delta = manifest.get("delta")
    _require(
        isinstance(delta, dict)
        and delta.get("file_count") == len(names)
        and delta.get("files_manifest") == list_path.name,
        "Manifest delta file count/list mismatch.",
    )
    migration_paths = {name for name in seen if name.startswith("backend/database/migrations/")}
    _require(
        manifest.get("contains_migrations") is bool(migration_paths),
        "Manifest migration-presence flag disagrees with ZIP.",
    )
    declared = manifest.get("migrations")
    _require(
        isinstance(declared, list)
        and all(isinstance(item, str) and item in migration_paths for item in declared),
        "Declared migrations are absent from ZIP.",
    )
    dashboard_fields = manifest.get("dashboard_fields")
    _require(
        isinstance(dashboard_fields, dict)
        and dashboard_fields.get("source_commit") == source
        and dashboard_fields.get("sha256") == digest,
        "Dashboard fields disagree with source or checksum.",
    )
    return {
        "from": installed_version,
        "to": target_version,
        "source_commit": source,
        "source_base": source_base,
        "sha256": digest,
        "files": len(names),
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--upgrade-124-125", type=Path, required=True)
    parser.add_argument("--upgrade-125-127", type=Path, required=True)
    parser.add_argument("--source-125", help="Expected immutable 1.0.125 source SHA")
    parser.add_argument("--source-127", help="Expected immutable 1.0.127 source SHA")
    args = parser.parse_args(argv)
    for raw in (args.source_125, args.source_127):
        if raw is not None:
            _require(bool(SHA_PATTERN.fullmatch(raw)), "Expected source must be a 40-char lowercase SHA.")
    first = validate_package(
        args.upgrade_124_125,
        installed_version="1.0.124",
        target_version="1.0.125",
        expected_source_commit=args.source_125,
    )
    second = validate_package(
        args.upgrade_125_127,
        installed_version="1.0.125",
        target_version="1.0.127",
        expected_source_commit=args.source_127,
    )
    _require(
        first["source_commit"] != second["source_commit"],
        "Distinct historical release edges may not claim identical source commits.",
    )
    print(json.dumps({"status": "archive_integrity_only", "verified_edges": [first, second]}, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (PackageContractError, OSError) as exc:
        print(f"G006 integrity FAIL: {exc}", file=sys.stderr)
        raise SystemExit(1) from exc
