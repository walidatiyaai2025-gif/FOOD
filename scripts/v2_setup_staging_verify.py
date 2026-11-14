#!/usr/bin/env python3
"""Verify a nonpublishing FOODEX Setup ZIP before CI fresh-install testing.

This is an integrity/safety check, NOT a trusted release signature verifier.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import stat
import unicodedata
import zipfile
from pathlib import Path


class SetupZipError(ValueError):
    """Archive cannot be accepted as a safe staging installation input."""


RESERVED_DEVICE = re.compile(
    r"^(?:con|conin\$|conout\$|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³])(?:\..*)?$",
    re.IGNORECASE,
)
SOURCE_SHA = re.compile(r"^[a-f0-9]{40}$")
REQUIRED = frozenset({
    "VERSION",
    "INSTALL-FIRST.txt",
    "backend/public/index.php",
    "backend/vendor/autoload.php",
    "backend/.env.production.example",
})
MAX_ENTRIES = 100_000
MAX_ENTRY_SIZE = 512 * 1024 * 1024
MAX_TOTAL_SIZE = 4 * 1024 * 1024 * 1024
ALLOWED_ENV_EXAMPLES = frozenset({".env.example", ".env.production.example"})


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise SetupZipError(message)


def _member_target(member: zipfile.ZipInfo) -> tuple[str, str, bool]:
    name = member.filename
    _require(bool(name) and "\\" not in name and not name.startswith("/"),
             f"Unsafe Setup ZIP path: {name!r}")
    _require(not re.match(r"^[a-zA-Z]:", name), f"Unsafe drive path: {name!r}")
    is_directory = member.is_dir()
    raw = name[:-1] if is_directory else name
    parts = raw.split("/")
    _require(all(part not in ("", ".", "..") for part in parts),
             f"Unsafe Setup ZIP component: {name!r}")

    for part in parts:
        _require(
            part == part.rstrip(" .")
            and not any(ch in '<>:"|?*' or ord(ch) < 32 or ord(ch) == 127 for ch in part)
            and not RESERVED_DEVICE.fullmatch(part),
            f"Unsafe cross-platform Setup ZIP component: {name!r}",
        )
        folded = part.casefold()
        _require(folded != ".git", f"Source control metadata in Setup ZIP: {name!r}")
        _require(
            not (folded == ".env" or (folded.startswith(".env.") and folded not in ALLOWED_ENV_EXAMPLES)),
            f"Secret environment configuration in Setup ZIP: {name!r}",
        )

    normalized = "/".join(parts)
    folded_parts = [p.casefold() for p in parts]
    # Empty private/log directories are needed for Laravel bootstrap; the
    # archive must not carry any stored runtime file below those paths.
    _require(not (
        not is_directory
        and len(folded_parts) >= 4
        and folded_parts[:3] == ["backend", "storage", "app"]
        and folded_parts[3] in ("private", "system")
    ), f"Private storage in Setup ZIP: {name!r}")
    _require(not (
        not is_directory
        and len(folded_parts) >= 3 and folded_parts[:3] == ["backend", "storage", "logs"]
    ), f"Runtime logs in Setup ZIP: {name!r}")

    # Runtime session, cache and compiled view/config files may contain
    # cookies, APP_KEY or database credentials from a dirty build tree.
    # Fresh Laravel only needs their parent directories, never the files.
    private_framework = (
        len(folded_parts) >= 4
        and folded_parts[:3] == ["backend", "storage", "framework"]
        and folded_parts[3] in ("sessions", "cache", "views", "testing")
    )
    compiled_bootstrap = (
        len(folded_parts) >= 3
        and folded_parts[:3] == ["backend", "bootstrap", "cache"]
    )
    _require(
        is_directory or not (private_framework or compiled_bootstrap),
        f"Runtime session/cache/config file in Setup ZIP: {name!r}",
    )

    # Info-ZIP Unicode Path (0x7075) is an alternate destination filename
    # that different ZIP extractors can interpret differently. A CRC-invalid
    # extra name may be silently ignored by Python while another installer
    # trusts it. Attest exactly one extraction path per archive member.
    offset = 0
    while offset < len(member.extra):
        _require(
            offset + 4 <= len(member.extra),
            f"Malformed Setup ZIP extra field: {name!r}",
        )
        field_id = int.from_bytes(member.extra[offset:offset + 2], "little")
        field_length = int.from_bytes(member.extra[offset + 2:offset + 4], "little")
        offset += 4
        _require(
            field_length <= len(member.extra) - offset,
            f"Malformed Setup ZIP extra field: {name!r}",
        )
        _require(
            field_id != 0x7075,
            f"Ambiguous Unicode Path Setup ZIP extra field: {name!r}",
        )
        offset += field_length

    mode = (member.external_attr >> 16) & 0xFFFF
    _require(not stat.S_ISLNK(mode), f"Symlink in Setup ZIP: {name!r}")
    file_type = stat.S_IFMT(mode)
    _require(file_type in ((0, stat.S_IFDIR) if is_directory else (0, stat.S_IFREG)),
             f"Non-regular Setup ZIP member: {name!r}")
    _require(member.flag_bits & 0x1 == 0, f"Encrypted Setup ZIP entry: {name!r}")
    _require(member.file_size <= MAX_ENTRY_SIZE, f"Oversize Setup ZIP entry: {name!r}")
    portable = unicodedata.normalize("NFC", normalized).casefold()
    return normalized, portable, is_directory


def verify_setup_zip(zip_path: Path, version_path: Path, source_sha: str) -> dict[str, str | int | bool]:
    _require(bool(SOURCE_SHA.fullmatch(source_sha)), "Invalid exact source SHA.")
    _require(zip_path.is_file() and not zip_path.is_symlink(), "Missing or symlinked Setup ZIP.")
    _require(version_path.is_file() and not version_path.is_symlink(), "Missing source VERSION.")
    version = version_path.read_text(encoding="utf-8").strip()
    _require(bool(re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", version)),
             "Invalid source Semantic Version.")

    files: set[str] = set()
    aliases: set[str] = set()
    portable_files: set[str] = set()
    size = 0
    try:
        with zipfile.ZipFile(zip_path) as archive:
            infos = archive.infolist()
            _require(len(infos) <= MAX_ENTRIES, "Setup ZIP member count exceeds budget.")
            for member in infos:
                name, portable, is_directory = _member_target(member)
                _require(portable not in aliases, f"Duplicate/Unicode/case alias: {name!r}")
                aliases.add(portable)
                size += member.file_size
                _require(size <= MAX_TOTAL_SIZE, "Setup ZIP expanded size exceeds budget.")
                if not is_directory:
                    files.add(name)
                    portable_files.add(portable)
            for name in aliases:
                components = name.split("/")
                for index in range(1, len(components)):
                    _require("/".join(components[:index]) not in portable_files,
                             f"File/directory path collision in Setup ZIP: {name!r}")
            _require(REQUIRED.issubset(files),
                     f"Missing Setup ZIP files: {sorted(REQUIRED - files)}")
            _require(archive.read("VERSION").decode("utf-8").strip() == version,
                     "ZIP VERSION differs from checked-out source.")
            _require(archive.testzip() is None, "Setup ZIP CRC verification failed.")
    except (OSError, ValueError, RuntimeError, NotImplementedError, zipfile.BadZipFile) as exc:
        if isinstance(exc, SetupZipError):
            raise
        raise SetupZipError("Unreadable or invalid Setup ZIP content.") from exc

    digest = hashlib.sha256()
    with zip_path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return {
        "schema_version": 1,
        "status": "UNTRUSTED_CI_STAGING_ONLY",
        "signed": False,
        "source_commit": source_sha,
        "version": version,
        "file": zip_path.name,
        "bytes": zip_path.stat().st_size,
        "sha256": digest.hexdigest(),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--zip", type=Path, required=True)
    parser.add_argument("--version-file", type=Path, required=True)
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--evidence", type=Path, required=True)
    args = parser.parse_args()
    result = verify_setup_zip(args.zip, args.version_file, args.source_commit)
    args.evidence.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
