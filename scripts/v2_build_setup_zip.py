#!/usr/bin/env python3
"""Build the G004 CI-only Setup ZIP with stable bytes for identical source trees.

This is not a release signer, authenticity verifier, or production publisher.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import os
import shutil
import stat
import tempfile
import zipfile
from pathlib import Path


class SetupBuildError(ValueError):
    """Unsafe or non-reproducible staging archive input."""


def _zip_timestamp(epoch: int) -> tuple[int, int, int, int, int, int]:
    moment = dt.datetime.fromtimestamp(epoch, tz=dt.timezone.utc)
    if not 1980 <= moment.year <= 2107:
        raise SetupBuildError("Commit timestamp is outside the portable ZIP date range.")
    # ZIP/DOS stores only even seconds. Use the Git commit timestamp, not CI time.
    return moment.year, moment.month, moment.day, moment.hour, moment.minute, moment.second // 2 * 2


def build_setup_zip(source_root: Path, output: Path, source_epoch: int) -> str:
    """Write a fresh, sorted, timestamp-normalized archive; return SHA-256."""
    if not source_root.is_dir() or source_root.is_symlink():
        raise SetupBuildError("Setup staging root must be a real directory.")
    source_root = source_root.resolve(strict=True)
    output = output.absolute()
    if output.exists() or output.is_symlink():
        raise SetupBuildError("Refusing to replace an existing Setup ZIP.")
    if output.parent.resolve() == source_root or source_root in output.parent.resolve().parents:
        raise SetupBuildError("Setup ZIP cannot be written inside its staging root.")

    timestamp = _zip_timestamp(source_epoch)
    paths = sorted(source_root.rglob("*"), key=lambda p: p.relative_to(source_root).as_posix().encode("utf-8"))
    if not paths:
        raise SetupBuildError("Setup ZIP staging root is empty.")

    output.parent.mkdir(parents=True, exist_ok=True)
    handle, temporary_name = tempfile.mkstemp(prefix=".v2-setup-", suffix=".zip", dir=output.parent)
    os.close(handle)
    temporary = Path(temporary_name)
    try:
        with zipfile.ZipFile(temporary, "w", allowZip64=True) as archive:
            for path in paths:
                meta = path.lstat()
                if stat.S_ISLNK(meta.st_mode) or not (stat.S_ISDIR(meta.st_mode) or stat.S_ISREG(meta.st_mode)):
                    raise SetupBuildError(f"Unsafe non-regular Setup staging member: {path.relative_to(source_root)!s}")
                if stat.S_ISREG(meta.st_mode) and meta.st_nlink > 1:
                    raise SetupBuildError(f"Hard-linked Setup staging member: {path.relative_to(source_root)!s}")

                name = path.relative_to(source_root).as_posix()
                is_dir = stat.S_ISDIR(meta.st_mode)
                info = zipfile.ZipInfo(name + "/" if is_dir else name, date_time=timestamp)
                info.create_system = 3
                info.external_attr = (
                    ((stat.S_IFDIR | 0o755) << 16) | 0x10
                    if is_dir else
                    ((stat.S_IFREG | (0o755 if meta.st_mode & 0o111 else 0o644)) << 16)
                )
                info.compress_type = zipfile.ZIP_STORED if is_dir else zipfile.ZIP_DEFLATED
                info.file_size = 0 if is_dir else meta.st_size
                if is_dir:
                    archive.writestr(info, b"")
                else:
                    with path.open("rb") as source, archive.open(info, "w", force_zip64=False) as target:
                        shutil.copyfileobj(source, target, length=1024 * 1024)
                    latest = path.lstat()
                    if (latest.st_size, latest.st_mtime_ns, latest.st_ino) != (
                        meta.st_size, meta.st_mtime_ns, meta.st_ino
                    ):
                        raise SetupBuildError(f"Setup staging member changed during packaging: {name}")
        os.replace(temporary, output)
    finally:
        temporary.unlink(missing_ok=True)

    checksum = hashlib.sha256()
    with output.open("rb") as archive_file:
        for chunk in iter(lambda: archive_file.read(1024 * 1024), b""):
            checksum.update(chunk)
    return checksum.hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--source-date-epoch", type=int, required=True)
    args = parser.parse_args()
    digest = build_setup_zip(args.source_root, args.output, args.source_date_epoch)
    print(f"UNTRUSTED_CI_STAGING_ONLY sha256={digest}")


if __name__ == "__main__":
    main()
