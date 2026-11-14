#!/usr/bin/env python3
"""G006: fail-closed integrity check for historical exact Dashboard Update ZIPs.

This tool validates archive/manifest consistency ONLY. It cannot authenticate
a signing key or prove MySQL migrations, backups, rollback or production readiness.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import re
import stat
import sys
import unicodedata
import zipfile
from pathlib import Path
from typing import Any


class PackageContractError(ValueError):
    """An update artifact is unsafe, inconsistent, or not the requested edge."""


SHA_PATTERN = re.compile(r"^[a-f0-9]{40}$")
DIGEST_PATTERN = re.compile(r"^[a-f0-9]{64}$")
VERSION_PATTERN = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)")
WINDOWS_DEVICE_PATTERN = re.compile(r"^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?$", re.IGNORECASE)


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise PackageContractError(message)


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def _unique_json_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    """Reject duplicate names in every object, including nested metadata."""
    data: dict[str, Any] = {}
    for key, value in pairs:
        _require(key not in data, "Duplicate update manifest JSON key.")
        data[key] = value
    return data


def _reject_nonfinite_json_constant(_token: str) -> Any:
    raise PackageContractError("Non-finite update manifest JSON number.")


def _parse_finite_json_float(token: str) -> float:
    # Even syntactically valid JSON exponents such as 1e999 must not silently
    # overflow Python's binary float representation to infinity.
    number = float(token)
    _require(math.isfinite(number), "Non-finite update manifest JSON number.")
    return number


def _read_json(path: Path) -> dict[str, Any]:
    try:
        data = json.loads(
            path.read_text(encoding="utf-8"),
            object_pairs_hook=_unique_json_object,
            parse_constant=_reject_nonfinite_json_constant,
            parse_float=_parse_finite_json_float,
        )
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
    # The ZIP may later be applied on Windows, macOS or shared hosts whose
    # pathname semantics differ from the CI runner. Reject ambiguous aliases
    # *before* treating the archive manifest as authoritative.
    _require(
        all(
            part == part.rstrip(" .")
            and not any(char in '<>:"|?*' or ord(char) < 32 or ord(char) == 127 for char in part)
            and not WINDOWS_DEVICE_PATTERN.fullmatch(part)
            for part in parts
        ),
        f"Unsafe cross-platform archive member component: {name!r}",
    )
    folded_parts = [part.casefold() for part in parts]
    folded_canonical = "/".join(folded_parts)
    mode = (info.external_attr >> 16) & 0xFFFF
    _require(not stat.S_ISLNK(mode), f"Symlink is not allowed: {name!r}")
    # A non-directory entry must be a regular file, never a Unix FIFO,
    # device, socket or disguised directory. An absent Unix file-type
    # field is permitted for ZIP archives created on other platforms.
    _require(
        stat.S_IFMT(mode) in (0, stat.S_IFREG),
        f"Non-regular archive member type is forbidden: {name!r}",
    )
    _require(not info.is_dir(), f"Directory entry is not expected: {name!r}")
    _require(
        not any(part == ".env" or part.startswith(".env.") for part in folded_parts)
        and "storage" not in folded_parts[:2]
        and not folded_canonical.startswith(("apps/", "release/", "docs/", ".github/"))
        and not folded_canonical.startswith("backend/tests/"),
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
    _require(
        directory.is_dir()
        and all(not component.is_symlink() for component in (directory, *directory.parents)),
        "Update package directory or ancestor is missing or a symbolic link.",
    )
    zip_path = directory / "FOODEX-Update.zip"
    manifest_path = directory / "FOODEX-Update.json"
    checksum_path = directory / "FOODEX-Update.sha256.txt"
    list_path = directory / "FOODEX-Update.files.txt"
    for path in (zip_path, manifest_path, checksum_path, list_path):
        _require(not path.is_symlink(), f"Symbolic link artifact is forbidden: {path.name}")
        _require(path.is_file(), f"Required artifact missing: {path.name}")
    manifest = _read_json(manifest_path)
    # Python considers True == 1 and 1.0 == 1. Release schemas must be
    # JSON integers, never a boolean or an ambiguously typed float.
    _require(
        type(manifest.get("schema_version")) is int and manifest["schema_version"] == 1,
        "Unexpected manifest schema.",
    )
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
            portable_seen: set[str] = set()
            for info in archive.infolist():
                name = _member_name(info)
                portable_name = unicodedata.normalize("NFC", name).casefold()
                _require(
                    name not in seen and portable_name not in portable_seen,
                    f"Duplicate or aliased archive target: {name}",
                )
                seen.add(name)
                portable_seen.add(portable_name)
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
        and type(delta.get("file_count")) is int
        and delta["file_count"] == len(names)
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
        and all(isinstance(item, str) for item in declared)
        and len(declared) == len(migration_paths)
        and set(declared) == migration_paths,
        "Declared migrations must exactly and uniquely match the ZIP inventory.",
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
