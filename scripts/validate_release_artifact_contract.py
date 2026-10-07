#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from typing import Any


class ContractError(RuntimeError):
    pass


def _fail(message: str) -> None:
    raise ContractError(message)


def _load_json(path: Path) -> dict[str, Any]:
    if not path.is_file():
        _fail(f"Missing required JSON file: {path}")
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        _fail(f"Invalid JSON in {path}: {exc}")
    if not isinstance(data, dict):
        _fail(f"Expected JSON object in {path}")
    return data


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _require_file(path: Path) -> None:
    if not path.is_file() or path.stat().st_size < 1:
        _fail(f"Missing or empty required release file: {path}")


def _require_equal(label: str, actual: object, expected: object) -> None:
    if actual != expected:
        _fail(f"{label} mismatch: expected {expected!r}, got {actual!r}")


def validate_release(
    release_dir: Path,
    *,
    version: str,
    build_number: int,
    source_commit: str,
) -> None:
    release_dir = release_dir.resolve()
    if not release_dir.is_dir():
        _fail(f"Release directory does not exist: {release_dir}")

    anonymous = sorted(release_dir.rglob("app-release.apk"))
    if anonymous:
        _fail(
            "Anonymous app-release.apk must not be a canonical release artifact: "
            + ", ".join(str(path.relative_to(release_dir)) for path in anonymous)
        )

    build_info = _load_json(release_dir / "BUILD_INFO.json")
    latest = _load_json(release_dir / "LATEST_RELEASE.json")

    _require_equal("BUILD_INFO version", build_info.get("version"), version)
    _require_equal("BUILD_INFO source_commit", build_info.get("source_commit"), source_commit)
    _require_equal("LATEST_RELEASE version", latest.get("version"), version)
    _require_equal("LATEST_RELEASE build_number", latest.get("build_number"), build_number)
    _require_equal("LATEST_RELEASE source_commit", latest.get("source_commit"), source_commit)

    latest_apps = latest.get("android_apps")
    if not isinstance(latest_apps, list):
        _fail("LATEST_RELEASE android_apps must be a list")

    latest_by_app: dict[str, dict[str, Any]] = {}
    for item in latest_apps:
        if not isinstance(item, dict):
            _fail("LATEST_RELEASE android_apps entries must be objects")
        app_name = str(item.get("app", "")).lower()
        if not app_name:
            _fail("LATEST_RELEASE android_apps entry is missing app identity")
        if app_name in latest_by_app:
            _fail(f"LATEST_RELEASE contains duplicate app entry: {app_name}")
        latest_by_app[app_name] = item

    expected_apps = {
        "customer": "Customer",
        "driver": "Driver",
        "van": "Van",
    }
    _require_equal(
        "LATEST_RELEASE app set",
        sorted(latest_by_app),
        sorted(expected_apps),
    )

    for key, display in expected_apps.items():
        alias = release_dir / f"FOODEX-{display}.apk"
        versioned = release_dir / f"FOODEX-{display}-{version}.apk"
        _require_file(alias)
        _require_file(versioned)

        alias_digest = _sha256(alias)
        versioned_digest = _sha256(versioned)
        _require_equal(f"{display} latest alias checksum", alias_digest, versioned_digest)

        info = build_info.get(key)
        if not isinstance(info, dict):
            _fail(f"BUILD_INFO is missing {key} metadata")
        _require_equal(f"BUILD_INFO {key} version", info.get("version"), version)
        _require_equal(f"BUILD_INFO {key} build_number", info.get("build_number"), build_number)
        _require_equal(f"BUILD_INFO {key} file", info.get("file"), versioned.name)
        _require_equal(f"BUILD_INFO {key} bytes", info.get("bytes"), versioned.stat().st_size)
        _require_equal(f"BUILD_INFO {key} sha256", info.get("sha256"), versioned_digest)

        latest_info = latest_by_app[key]
        _require_equal(f"LATEST_RELEASE {key} version", latest_info.get("version"), version)
        _require_equal(
            f"LATEST_RELEASE {key} build_number",
            latest_info.get("build_number"),
            build_number,
        )
        _require_equal(f"LATEST_RELEASE {key} file", latest_info.get("file"), versioned.name)
        _require_equal(
            f"LATEST_RELEASE {key} latest_alias",
            latest_info.get("latest_alias"),
            alias.name,
        )
        _require_equal(
            f"LATEST_RELEASE {key} bytes",
            latest_info.get("bytes"),
            versioned.stat().st_size,
        )
        _require_equal(
            f"LATEST_RELEASE {key} sha256",
            latest_info.get("sha256"),
            versioned_digest,
        )

    setup = release_dir / "FOODEX-Laravel-Setup.zip"
    _require_file(setup)
    setup_info = build_info.get("laravel_setup")
    if not isinstance(setup_info, dict):
        _fail("BUILD_INFO is missing laravel_setup metadata")
    _require_equal("BUILD_INFO laravel_setup file", setup_info.get("file"), setup.name)
    _require_equal("BUILD_INFO laravel_setup bytes", setup_info.get("bytes"), setup.stat().st_size)
    _require_equal("BUILD_INFO laravel_setup sha256", setup_info.get("sha256"), _sha256(setup))

    updates = release_dir / "Updates"
    manifest_path = updates / "FOODEX-Update.json"
    update_manifest = _load_json(manifest_path)
    _require_equal("Dashboard update target_version", update_manifest.get("target_version"), version)
    _require_equal("Dashboard update package name", update_manifest.get("package"), "FOODEX-Update.zip")

    latest_dashboard = latest.get("dashboard_update")
    if not isinstance(latest_dashboard, dict):
        _fail("LATEST_RELEASE is missing dashboard_update metadata")
    _require_equal("LATEST_RELEASE dashboard directory", latest_dashboard.get("directory"), "Updates")
    _require_equal("LATEST_RELEASE dashboard package", latest_dashboard.get("package"), "FOODEX-Update.zip")
    _require_equal(
        "LATEST_RELEASE dashboard manifest",
        latest_dashboard.get("manifest"),
        "FOODEX-Update.json",
    )

    full_redeploy = bool(update_manifest.get("requires_full_redeploy"))
    package = updates / "FOODEX-Update.zip"
    checksum_path = updates / "FOODEX-Update.sha256.txt"
    files_path = updates / "FOODEX-Update.files.txt"

    if full_redeploy:
        return

    _require_file(package)
    _require_file(checksum_path)
    _require_file(files_path)
    digest = _sha256(package)
    _require_equal("Dashboard update sha256", update_manifest.get("sha256"), digest)
    checksum = checksum_path.read_text(encoding="utf-8").strip().split()
    if not checksum:
        _fail(f"Empty checksum file: {checksum_path}")
    _require_equal("Dashboard update checksum file", checksum[0], digest)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Validate the synchronized FOODEX Dashboard + Customer + Driver + Van release artifact contract."
    )
    parser.add_argument("--release-dir", type=Path, default=Path("Release"))
    parser.add_argument("--version", required=True)
    parser.add_argument("--build-number", required=True, type=int)
    parser.add_argument("--source-commit", required=True)
    args = parser.parse_args()

    try:
        validate_release(
            args.release_dir,
            version=args.version,
            build_number=args.build_number,
            source_commit=args.source_commit,
        )
    except ContractError as exc:
        raise SystemExit(f"Release artifact contract failed: {exc}") from exc

    print(
        "Release artifact contract PASS: Dashboard + Customer + Driver + Van "
        f"{args.version}+{args.build_number} from {args.source_commit}"
    )


if __name__ == "__main__":
    main()
