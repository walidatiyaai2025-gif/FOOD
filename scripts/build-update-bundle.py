#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import subprocess
import sys
import zipfile

RUNTIME_PREFIXES = (
    "backend/app/",
    "backend/bootstrap/",
    "backend/config/",
    "backend/database/migrations/",
    "backend/database/seeders/",
    "backend/lang/",
    "backend/public/",
    "backend/resources/",
    "backend/routes/",
)

RUNTIME_EXACT = {
    "VERSION",
    "backend/artisan",
    "backend/composer.json",
    "backend/composer.lock",
}

RUNTIME_ALWAYS_INCLUDE = {
    "backend/public/brand/foodex-economical-group.webp",
}

PROTECTED_PARTS = {".git", "storage", "vendor"}
FIXED_ZIP_TIME = (2026, 9, 27, 0, 0, 0)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build a deterministic FOODEX dashboard update bundle.")
    parser.add_argument("--base", required=True, help="Source commit for the minimum supported installed release.")
    parser.add_argument("--target-version", required=True)
    parser.add_argument("--minimum-current-version", required=True)
    parser.add_argument("--output-dir", default="dist/update")
    parser.add_argument("--release-notes", required=True)
    return parser.parse_args()


def run_git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    return result.stdout


def is_runtime_path(path: str) -> bool:
    return path in RUNTIME_EXACT or path.startswith(RUNTIME_PREFIXES)


def safe_path(path: str) -> str:
    value = path.replace("\\", "/").strip()
    pure = PurePosixPath(value)

    if not value or value.startswith("/") or ".." in pure.parts:
        raise RuntimeError(f"Unsafe update path: {path}")

    if any(part in PROTECTED_PARTS for part in pure.parts):
        raise RuntimeError(f"Protected runtime path is not allowed: {path}")

    if pure.name == ".env" or ".env" in pure.parts:
        raise RuntimeError(f"Environment files are not allowed in updates: {path}")

    return value


def changed_runtime_files(base: str) -> list[str]:
    diff = run_git("diff", "--name-status", "--find-renames", f"{base}..HEAD")
    selected: set[str] = set()

    for raw in diff.splitlines():
        if not raw.strip():
            continue

        parts = raw.split("\t")
        status = parts[0]

        if status.startswith("R") or status.startswith("C"):
            if len(parts) != 3:
                raise RuntimeError(f"Unexpected git diff row: {raw}")
            old_path, new_path = parts[1], parts[2]

            if is_runtime_path(old_path) and status.startswith("R"):
                raise RuntimeError(
                    f"Runtime rename/delete is unsupported by the current updater: {old_path} -> {new_path}"
                )
            if is_runtime_path(new_path):
                selected.add(safe_path(new_path))
            continue

        if len(parts) != 2:
            raise RuntimeError(f"Unexpected git diff row: {raw}")

        path = parts[1]
        if not is_runtime_path(path):
            continue

        if status.startswith("D"):
            raise RuntimeError(
                f"Runtime deletion is unsupported by the current updater: {path}"
            )

        if status[0] in {"A", "M", "T"}:
            selected.add(safe_path(path))

    selected.add("VERSION")
    selected.update(RUNTIME_ALWAYS_INCLUDE)
    return sorted(selected)


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def build_zip(repo_root: Path, files: list[str], output: Path) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)

    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for relative in files:
            source = repo_root / relative
            if not source.is_file():
                raise RuntimeError(f"Selected update file is missing: {relative}")

            info = zipfile.ZipInfo(relative, date_time=FIXED_ZIP_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (0o100644 & 0xFFFF) << 16
            archive.writestr(info, source.read_bytes())

    with zipfile.ZipFile(output, "r") as archive:
        names = archive.namelist()
        if names != files:
            raise RuntimeError("Update ZIP file list does not match the deterministic selection.")
        for name in names:
            safe_path(name)
            if name.endswith("/"):
                raise RuntimeError(f"Unexpected directory entry in update ZIP: {name}")


def main() -> int:
    args = parse_args()
    repo_root = Path(run_git("rev-parse", "--show-toplevel").strip())

    installed_version = (repo_root / "VERSION").read_text(encoding="utf-8").strip()
    if installed_version != args.target_version:
        raise RuntimeError(
            f"VERSION contains {installed_version!r}; expected target {args.target_version!r}."
        )

    run_git("cat-file", "-e", f"{args.base}^{{commit}}")
    files = changed_runtime_files(args.base)

    migrations = [path for path in files if path.startswith("backend/database/migrations/")]
    contains_migrations = bool(migrations)

    output_dir = repo_root / args.output_dir
    output_dir.mkdir(parents=True, exist_ok=True)

    package = output_dir / "FOODEX-Update.zip"
    manifest_path = output_dir / "FOODEX-Update.json"
    files_path = output_dir / "FOODEX-Update.files.txt"
    hash_path = output_dir / "FOODEX-Update.sha256.txt"

    build_zip(repo_root, files, package)
    package_hash = sha256(package)

    manifest = {
        "schema_version": 1,
        "available": True,
        "package": package.name,
        "target_version": args.target_version,
        "minimum_current_version": args.minimum_current_version,
        "sha256": package_hash,
        "contains_migrations": contains_migrations,
        "requires_full_redeploy": False,
        "reason": None,
        "release_notes": args.release_notes,
        "dashboard_fields": {
            "target_version": args.target_version,
            "minimum_current_version": args.minimum_current_version,
            "sha256": package_hash,
            "contains_migrations": contains_migrations,
            "release_notes": args.release_notes,
        },
    }

    manifest_path.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    files_path.write_text("\n".join(files) + "\n", encoding="utf-8")
    hash_path.write_text(f"{package_hash}  {package.name}\n", encoding="utf-8")

    print(f"FOODEX update package: {package}")
    print(f"target_version={args.target_version}")
    print(f"minimum_current_version={args.minimum_current_version}")
    print(f"contains_migrations={str(contains_migrations).lower()}")
    print(f"file_count={len(files)}")
    print(f"migration_count={len(migrations)}")
    print(f"sha256={package_hash}")

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise
