#!/usr/bin/env python3
"""Verify three ephemeral V2 Android CI APKs built from one exact PR head.

This is *not* a trusted-signature or production-release verifier.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from typing import Any

APPS = {
    "customer": "com.fiftysolution.foodex.customer",
    "driver": "com.fiftysolution.foodex.driver",
    "van": "com.foodex.van",
}
CI_PURPOSE = "ci-validation-only"
CI_SIGNING = "flutter-template-debug-key"
API_BASE = "https://foodex.50sols.com"


class TrioEvidenceError(ValueError):
    """Missing, tampered or falsely certified V2 Android CI evidence."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise TrioEvidenceError(message)


def safe_file(path: Path) -> None:
    require(not path.is_symlink(), "Symbolic links are forbidden in CI artifact evidence.")
    require(path.is_file() and path.stat().st_size > 0, "Required CI evidence file is missing or empty.")


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def validate(
    artifact_root: Path,
    source_commit: str,
    build_number: int,
    platform_version: str,
) -> dict[str, Any]:
    require(bool(re.fullmatch(r"[a-f0-9]{40}", source_commit)), "Exact 40-character source SHA is required.")
    require(type(build_number) is int and build_number > 0, "Positive integer build number required.")
    require(bool(re.fullmatch(r"\d+\.\d+\.\d+", platform_version)), "Canonical platform version required.")
    require(artifact_root.is_dir() and not artifact_root.is_symlink(), "Artifact directory is missing or linked.")

    expected_directories = {f"v2-trio-{app}-{source_commit}" for app in APPS}
    actual_directories = {p.name for p in artifact_root.iterdir()}
    require(actual_directories == expected_directories, "Three and only three distinct V2 application artifacts are required.")

    version: str | None = None
    rows: list[dict[str, Any]] = []
    for app, expected_identity in APPS.items():
        directory = artifact_root / f"v2-trio-{app}-{source_commit}"
        require(directory.is_dir() and not directory.is_symlink(), f"Invalid {app} evidence directory.")
        manifest_path = directory / "manifest.json"
        checksum_path = directory / "SHA256SUMS"
        safe_file(manifest_path)
        safe_file(checksum_path)
        try:
            metadata = json.loads(manifest_path.read_text(encoding="utf-8"))
        except (OSError, ValueError, UnicodeError) as exc:
            raise TrioEvidenceError(f"Invalid {app} manifest.") from exc
        require(type(metadata) is dict, f"{app} manifest must be an object.")

        required = {
            "schema_version": 1,
            "app": app,
            "platform": "android",
            "source_commit": source_commit,
            "platform_version": platform_version,
            "build_number": str(build_number),
            "build_mode": "release",
            "purpose": CI_PURPOSE,
            "production_ready": False,
            "api_base_url": API_BASE,
            "native_identity": expected_identity,
            "signing": CI_SIGNING,
        }
        for key, value in required.items():
            require(type(metadata.get(key)) is type(value) and metadata[key] == value,
                    f"{app} manifest has invalid {key}.")

        app_version = metadata.get("mobile_version")
        require(type(app_version) is str and bool(re.fullmatch(r"\d+\.\d+\.\d+", app_version)),
                f"{app} mobile version is not canonical.")
        if version is None:
            version = app_version
        require(app_version == version, "V2 Customer, Driver and Van mobile versions must match.")

        filename = f"foodex-{app}-android-{app_version}+{build_number}-{source_commit[:12]}-validation.apk"
        require(metadata.get("artifact") == filename, f"{app} artifact filename/provenance mismatch.")
        apk = directory / filename
        safe_file(apk)
        require(type(metadata.get("bytes")) is int and metadata["bytes"] == apk.stat().st_size,
                f"{app} APK length mismatch.")
        digest = sha256(apk)
        require(metadata.get("sha256") == digest, f"{app} APK SHA-256 mismatch.")
        require(checksum_path.read_text(encoding="utf-8") == f"{digest}  {filename}\n",
                f"{app} checksum file mismatch.")
        require({p.name for p in directory.iterdir()} == {filename, "manifest.json", "SHA256SUMS", "README.txt"},
                f"{app} artifact set contains missing or unexpected files.")
        safe_file(directory / "README.txt")
        rows.append({
            "app": app,
            "native_identity": expected_identity,
            "filename": filename,
            "bytes": apk.stat().st_size,
            "sha256": digest,
            "signing": CI_SIGNING,
        })

    require(version is not None, "Empty app set.")
    return {
        "schema_version": 1,
        "source_issue": 3342,
        "parent_gate": 2749,
        "source_commit": source_commit,
        "platform_version": platform_version,
        "mobile_version": version,
        "build_number": build_number,
        "purpose": CI_PURPOSE,
        "production_ready": False,
        "trusted_signing": False,
        "release_published": False,
        "artifacts": rows,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--artifact-root", type=Path, required=True)
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--build-number", type=int, required=True)
    parser.add_argument("--platform-version", required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    try:
        summary = validate(args.artifact_root, args.source_commit, args.build_number, args.platform_version)
    except TrioEvidenceError as exc:
        parser.exit(status=1, message=f"::error::V2 Android trio evidence rejected: {exc}\n")
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print("V2 three-app exact-source Android CI artifacts verified; trusted signing NOT certified.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
