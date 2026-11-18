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
REQUIRED_PUBLIC_ASSETS = frozenset({
    "backend/public/assets/admin/assistant-chat.css",
    "backend/public/assets/admin/assistant-chat.js",
    "backend/public/assets/wholesale/foodex-wholesale.css",
    "backend/public/assets/wholesale/foodex-wholesale.js",
    "backend/public/css/smart-ordering-storefront.css",
    "backend/public/js/smart-ordering-storefront.js",
})
MAX_ENTRIES = 100_000
MAX_ENTRY_SIZE = 512 * 1024 * 1024
MAX_TOTAL_SIZE = 4 * 1024 * 1024 * 1024
ALLOWED_ENV_EXAMPLES = frozenset({".env.example", ".env.production.example"})
REQUIRED_INSTALL_INSTRUCTION_MARKERS = (
    "cpanel",
    "backend/public",
    "pdo_mysql",
    "fileinfo",
    "mysql/mariadb",
    "cron jobs",
    "symlink",
)
FORBIDDEN_INSTALL_INSTRUCTION_VALUES = (
    "foodex.50sols.com",
    "solscool_foodex",
    "solscool_vanfoodex",
    "noreply@50sols.com",
)
SENSITIVE_ENV_KEYS = frozenset({
    "APP_KEY",
    "DB_PASSWORD",
    "MAIL_PASSWORD",
    "REDIS_PASSWORD",
    "AWS_ACCESS_KEY_ID",
    "AWS_SECRET_ACCESS_KEY",
    "PUSHER_APP_SECRET",
    "SENTRY_DSN",
    "FOODEX_WHATSAPP_ACCESS_TOKEN",
    "FOODEX_WHATSAPP_APP_SECRET",
    "FOODEX_WHATSAPP_VERIFY_TOKEN",
    "TWILIO_AUTH_TOKEN",
})


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise SetupZipError(message)


def _parse_env_template(content: str) -> dict[str, list[str]]:
    """Parse every dotenv assignment so duplicate keys cannot hide secrets."""
    values: dict[str, list[str]] = {}
    for raw_line in content.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        values.setdefault(key, []).append(value)
    return values


def _reject_ambiguous_extra_fields(extra: bytes, member_name: str) -> None:
    """Reject alternate extraction names in either ZIP header."""
    offset = 0
    while offset < len(extra):
        _require(offset + 4 <= len(extra),
                 f"Malformed Setup ZIP extra field: {member_name!r}")
        field_id = int.from_bytes(extra[offset:offset + 2], "little")
        field_length = int.from_bytes(extra[offset + 2:offset + 4], "little")
        offset += 4
        _require(field_length <= len(extra) - offset,
                 f"Malformed Setup ZIP extra field: {member_name!r}")
        _require(field_id != 0x7075,
                 f"Ambiguous Unicode Path Setup ZIP extra field: {member_name!r}")
        offset += field_length


def _verify_local_header(archive: zipfile.ZipFile, member: zipfile.ZipInfo) -> None:
    """Match the local extraction header to the attested central directory."""
    source = archive.fp
    _require(source is not None and member.header_offset >= 0,
             f"Invalid Setup ZIP local header offset: {member.filename!r}")
    source.seek(member.header_offset)
    header = source.read(30)
    _require(len(header) == 30 and header[:4] == b"PK\x03\x04",
             f"Invalid Setup ZIP local header: {member.filename!r}")
    flags = int.from_bytes(header[6:8], "little")
    method = int.from_bytes(header[8:10], "little")
    _require(flags == member.flag_bits,
             f"Conflicting local/central Setup ZIP flags: {member.filename!r}")
    _require(method == member.compress_type,
             f"Conflicting local/central Setup ZIP compression: {member.filename!r}")

    # Python uses CENTRAL metadata for integrity checks, while another
    # shared-host extractor may trust the LOCAL header. Reject disagreement
    # before unpacking even if ZipFile.testzip() would accept this archive.
    local_crc = int.from_bytes(header[14:18], "little")
    local_compressed = int.from_bytes(header[18:22], "little")
    local_uncompressed = int.from_bytes(header[22:26], "little")
    if flags & 0x08:
        # Data-descriptor entries may legitimately use zero placeholders.
        _require(local_crc in (0, member.CRC),
                 f"Conflicting local/central Setup ZIP CRC: {member.filename!r}")
        _require(local_compressed in (0, member.compress_size)
                 and local_uncompressed in (0, member.file_size),
                 f"Conflicting local/central Setup ZIP sizes: {member.filename!r}")
    else:
        _require(local_crc == member.CRC,
                 f"Conflicting local/central Setup ZIP CRC: {member.filename!r}")
        _require(local_compressed == member.compress_size
                 and local_uncompressed == member.file_size,
                 f"Conflicting local/central Setup ZIP sizes: {member.filename!r}")

    filename_size = int.from_bytes(header[26:28], "little")
    extra_size = int.from_bytes(header[28:30], "little")
    filename = source.read(filename_size)
    _require(len(filename) == filename_size,
             f"Truncated Setup ZIP local filename: {member.filename!r}")
    try:
        decoded_name = filename.decode("utf-8" if flags & 0x800 else "cp437")
    except UnicodeDecodeError as exc:
        raise SetupZipError("Invalid Setup ZIP local filename encoding.") from exc
    _require(decoded_name == member.filename,
             f"Conflicting local/central Setup ZIP filename: {member.filename!r}")
    extra = source.read(extra_size)
    _require(len(extra) == extra_size,
             f"Truncated Setup ZIP local extra field: {member.filename!r}")
    _reject_ambiguous_extra_fields(extra, member.filename + " (local)")

    if flags & 0x08:
        # A streaming ZIP has a data descriptor after the compressed payload.
        # Python ZipFile.testzip() trusts CENTRAL CRC/sizes and ignores these
        # descriptor fields, but shared-host unzip tools may consume them.
        descriptor_offset = (
            member.header_offset + 30 + filename_size + extra_size
            + member.compress_size
        )
        source.seek(descriptor_offset)
        marker = source.read(4)
        _require(len(marker) == 4,
                 f"Truncated Setup ZIP data descriptor: {member.filename!r}")
        descriptor = (source.read(12) if marker == b"PK\x07\x08"
                      else marker + source.read(8))
        _require(len(descriptor) == 12,
                 f"Truncated Setup ZIP data descriptor: {member.filename!r}")
        declared_crc = int.from_bytes(descriptor[0:4], "little")
        declared_compressed = int.from_bytes(descriptor[4:8], "little")
        declared_uncompressed = int.from_bytes(descriptor[8:12], "little")
        _require(
            (declared_crc, declared_compressed, declared_uncompressed)
            == (member.CRC, member.compress_size, member.file_size),
            f"Conflicting Setup ZIP data descriptor: {member.filename!r}",
        )


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
    # Public upload roots are live runtime data, not fresh-install source.
    # Reject the roots themselves (including directory-only ZIP members)
    # because they can shadow an installer-created public/storage symlink;
    # also reject every descendant, regardless of path casing.
    public_upload_roots = (
        ("backend", "public", "storage"),
        ("backend", "public", "uploads"),
        ("public", "storage"),
        ("public", "uploads"),
        ("backend", "uploads"),
    )
    _require(
        not any(tuple(folded_parts[:len(root)]) == root for root in public_upload_roots),
        f"Public uploaded/runtime content in Setup ZIP: {name!r}",
    )

    # Fresh Setup ZIPs may include empty Laravel storage directories, but
    # must not carry uploaded files from the public disk or private/system
    # runtime data from a dirty checkout/build workspace.
    _require(not (
        not is_directory
        and len(folded_parts) >= 4
        and folded_parts[:3] == ["backend", "storage", "app"]
        and folded_parts[3] in ("private", "system", "public")
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

    # The entire Laravel storage tree is runtime state. A dirty build may
    # contain a backup/tenant export directly under storage/app, or a new
    # framework directory not covered by a narrower known-subdir blacklist.
    # Empty directories are safe; no storage file can belong to clean Setup.
    _require(
        is_directory or folded_parts[:2] != ["backend", "storage"],
        f"Runtime storage data in Setup ZIP: {name!r}",
    )

    # Both central and local ZIP metadata must attest to one path only.
    _reject_ambiguous_extra_fields(member.extra, name)

    mode = (member.external_attr >> 16) & 0xFFFF
    _require(not stat.S_ISLNK(mode), f"Symlink in Setup ZIP: {name!r}")
    file_type = stat.S_IFMT(mode)
    _require(file_type in ((0, stat.S_IFDIR) if is_directory else (0, stat.S_IFREG)),
             f"Non-regular Setup ZIP member: {name!r}")
    _require(member.flag_bits & 0x1 == 0, f"Encrypted Setup ZIP entry: {name!r}")
    # Restrict general-purpose ZIP flags to portable, explicitly supported features.
    # ZipFile.testzip() ignores reserved/encrypted-directory flags that some
    # cPanel/shared-host extractors may interpret differently during extraction.
    supported_flags = 0x080E  # DEFLATE levels, data descriptor, UTF-8 filenames
    _require(member.flag_bits & ~supported_flags == 0,
             f"Unsupported Setup ZIP general-purpose flags: {name!r}")
    # The web installer must be extractable by ordinary cPanel/shared-host ZIP
    # handlers. Python can read BZIP2/LZMA entries that typical hosting unzip
    # tools may reject, so do not certify a nonportable one-ZIP installer.
    _require(member.compress_type in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED),
             f"Unsupported Setup ZIP compression method: {name!r}")
    _require(member.file_size <= MAX_ENTRY_SIZE, f"Oversize Setup ZIP entry: {name!r}")
    portable = unicodedata.normalize("NFC", normalized).casefold()
    return normalized, portable, is_directory


def _reject_noncanonical_zip_envelope(zip_path: Path) -> None:
    """Reject valid-CRC ZIP wrappers that shared-host extractors may interpret differently.

    Python zipfile accepts self-extracting PHP stubs, appended payloads and
    ZIP comments. A G004 one-ZIP installer is a plain archive with its first
    local header at byte zero and its zero-comment EOCD as the final 22 bytes.
    """
    try:
        with zip_path.open("rb") as stream:
            stream.seek(0, 2)
            length = stream.tell()
            _require(length >= 22, "Setup ZIP has no canonical end record.")
            stream.seek(0)
            _require(stream.read(4) == b"PK\x03\x04",
                     "Setup ZIP contains prepended data or an invalid first header.")
            stream.seek(length - 22)
            end_record = stream.read(22)
    except OSError as exc:
        raise SetupZipError("Setup ZIP envelope is not readable.") from exc

    _require(end_record[:4] == b"PK\x05\x06"
             and end_record[20:22] == b"\x00\x00",
             "Setup ZIP contains appended data, an archive comment or an ambiguous end record.")


def verify_setup_zip(zip_path: Path, version_path: Path, source_sha: str) -> dict[str, str | int | bool]:
    _require(bool(SOURCE_SHA.fullmatch(source_sha)), "Invalid exact source SHA.")
    _require(zip_path.is_file() and not zip_path.is_symlink(), "Missing or symlinked Setup ZIP.")
    _require(version_path.is_file() and not version_path.is_symlink(), "Missing source VERSION.")
    version = version_path.read_text(encoding="utf-8").strip()
    _require(bool(re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", version)),
             "Invalid source Semantic Version.")

    _reject_noncanonical_zip_envelope(zip_path)

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
                _verify_local_header(archive, member)
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
            # These files are *already* served by existing Dashboard and
            # storefront routes. They must be shipped with the one-ZIP
            # Laravel install, not accidentally omitted by a build step.
            _require(REQUIRED_PUBLIC_ASSETS.issubset(files),
                     f"Missing compiled/public frontend assets: {sorted(REQUIRED_PUBLIC_ASSETS - files)}")
            for asset_name in sorted(REQUIRED_PUBLIC_ASSETS):
                _require(archive.getinfo(asset_name).file_size > 0,
                         f"Empty compiled/public frontend asset: {asset_name}")
            try:
                instructions = archive.read("INSTALL-FIRST.txt").decode("utf-8").casefold()
            except (KeyError, UnicodeDecodeError) as exc:
                raise SetupZipError("Invalid Setup ZIP first-install instructions.") from exc
            _require(
                all(marker in instructions for marker in REQUIRED_INSTALL_INSTRUCTION_MARKERS),
                "Setup ZIP first-install instructions omit cPanel/PHP/storage/scheduler guidance.",
            )
            _require(
                not any(value in instructions for value in FORBIDDEN_INSTALL_INSTRUCTION_VALUES),
                "Setup ZIP first-install instructions contain deployment-specific production values.",
            )
            try:
                env_template = archive.read("backend/.env.production.example").decode("utf-8")
            except (KeyError, UnicodeDecodeError) as exc:
                raise SetupZipError("Invalid Setup ZIP environment template.") from exc
            env_values = _parse_env_template(env_template)
            unsafe_secret_keys = []
            for key in SENSITIVE_ENV_KEYS:
                allowed_values = {"", "null"} if key == "REDIS_PASSWORD" else {""}
                if any(value.casefold() not in allowed_values for value in env_values.get(key, [])):
                    unsafe_secret_keys.append(key)
            _require(
                not unsafe_secret_keys,
                "Setup ZIP environment template contains non-empty secret fields.",
            )
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
