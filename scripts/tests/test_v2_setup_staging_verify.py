"""Isolated adversarial tests for nonpublishing V2 Setup ZIP verification."""
from __future__ import annotations

import hashlib
import io
import stat
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

from scripts.v2_setup_staging_verify import SetupZipError, verify_setup_zip


SHA = "a" * 40
BASE_ENTRIES = {
    "VERSION": b"1.0.125",
    "INSTALL-FIRST.txt": (
        b"cPanel shared hosting: set Document Root to backend/public. "
        b"Create an empty MySQL/MariaDB database. PHP requires pdo_mysql and fileinfo. "
        b"Verify the storage symlink. Use cPanel Cron Jobs for schedule:run."
    ),
    "backend/public/index.php": b"<?php",
    "backend/public/assets/admin/assistant-chat.css": b"/* compiled admin CSS */",
    "backend/public/assets/admin/assistant-chat.js": b"'use strict';",
    "backend/public/assets/wholesale/foodex-wholesale.css": b"/* bundled wholesale CSS */",
    "backend/public/assets/wholesale/foodex-wholesale.js": b"'use strict';",
    "backend/public/css/smart-ordering-storefront.css": b"/* bundled storefront CSS */",
    "backend/public/js/smart-ordering-storefront.js": b"'use strict';",
    "backend/vendor/autoload.php": b"<?php",
    "backend/.env.production.example": (
        b"APP_ENV=production\n"
        b"APP_KEY=\n"
        b"APP_URL=http://localhost\n"
        b"DB_DATABASE=foodex\n"
        b"DB_USERNAME=foodex\n"
        b"DB_PASSWORD=\n"
        b"REDIS_PASSWORD=null\n"
        b"MAIL_PASSWORD=\n"
        b"MAIL_FROM_ADDRESS=noreply@example.com\n"
        b"FOODEX_CUSTOMER_PREVIEW_RUNTIME_URL=${APP_URL}/preview/customer/\n"
        b"FOODEX_CUSTOMER_PREVIEW_ALLOWED_ORIGIN=${APP_URL}\n"
    ),
}


class V2SetupStagingVerifierTest(unittest.TestCase):
    def setUp(self) -> None:
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        self.archive = self.root / "FOODEX-Laravel-Setup.zip"
        self.version = self.root / "VERSION"
        self.version.write_text("1.0.125\n", encoding="utf-8")
        self.pack()

    def pack(self, extra: dict[str, bytes] | None = None, mode: dict[str, int] | None = None) -> None:
        all_files = {**BASE_ENTRIES, **(extra or {})}
        with zipfile.ZipFile(self.archive, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            for name, payload in all_files.items():
                member = zipfile.ZipInfo(name)
                # ZipInfo normalizes backslashes on Windows: preserve hostile
                # names to test the same on-disk member across hosts.
                member.filename = name
                member.external_attr = (mode.get(name, stat.S_IFREG | 0o644)
                                        if mode else stat.S_IFREG | 0o644) << 16
                archive.writestr(member, payload)

    def verify(self) -> dict[str, str | int | bool]:
        return verify_setup_zip(self.archive, self.version, SHA)

    def test_genuine_setup_zip_passes_with_staging_only_evidence(self) -> None:
        result = self.verify()
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", result["status"])
        self.assertIs(False, result["signed"])
        self.assertEqual(SHA, result["source_commit"])
        self.assertEqual(hashlib.sha256(self.archive.read_bytes()).hexdigest(), result["sha256"])

    def test_setup_zip_rejects_missing_required_frontend_css_and_js(self) -> None:
        # Missing public assets can produce an apparently successful Laravel
        # installation with broken Admin or storefront functionality.
        for asset_name in (
            "backend/public/assets/admin/assistant-chat.css",
            "backend/public/js/smart-ordering-storefront.js",
        ):
            with self.subTest(asset=asset_name):
                entries = {key: value for key, value in BASE_ENTRIES.items()
                           if key != asset_name}
                with zipfile.ZipFile(self.archive, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                    for key, payload in entries.items():
                        archive.writestr(key, payload)
                with self.assertRaisesRegex(SetupZipError, "Missing compiled/public frontend assets"):
                    self.verify()

    def test_setup_zip_rejects_empty_frontend_assets(self) -> None:
        for asset_name in (
            "backend/public/assets/wholesale/foodex-wholesale.css",
            "backend/public/assets/admin/assistant-chat.js",
        ):
            with self.subTest(asset=asset_name):
                self.pack({asset_name: b""})
                with self.assertRaisesRegex(SetupZipError, "Empty compiled/public frontend asset"):
                    self.verify()

    def test_required_frontend_assets_are_nonempty_in_accepted_setup(self) -> None:
        result = self.verify()
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", result["status"])

    def test_secret_env_and_runtime_private_members_fail_closed(self) -> None:
        for entry in (
            "backend/.env",
            "backend/.env.local",
            "backend/.env.production",
            "backend/.env.testing",
            "backend/vendor/pkg/.ENV.production",
            "backend/storage/app/private/key.json",
            "backend/storage/app/public/customer-uploads/photo.jpg",
            "backend/Storage/App/Public/SECRET-ATTACHMENT.pdf",
            "backend/storage/app/system/settings.json",
            "backend/storage/logs/laravel.log",
            "backend/.git/config",
        ):
            with self.subTest(entry=entry):
                self.pack({entry: b"secret"})
                with self.assertRaisesRegex(SetupZipError, "Secret|Private|Runtime|Source control"):
                    self.verify()

    def test_all_runtime_storage_files_fail_closed_even_outside_known_subdirs(self) -> None:
        # A dirty build can contain backups, exports or tokens directly under
        # storage/app or an unexpected framework path. Their absence from a
        # narrow upload/session blacklist must never permit a Setup package.
        runtime_files = (
            "backend/storage/app/backup.sqlite",
            "backend/storage/app/tenant-export.csv",
            "backend/storage/framework/other/runtime-token.json",
            "backend/storage/exports/customer-ledger.xlsx",
            "backend/storage/debug.txt",
            "backend/Storage/APP/.gitignore",
        )
        for entry in runtime_files:
            with self.subTest(entry=entry):
                self.pack({entry: b"PRIVATE CUSTOMER DATA"})
                with self.assertRaisesRegex(SetupZipError, "Runtime storage"):
                    self.verify()

    def test_public_upload_paths_and_symlink_roots_cannot_ship_in_setup(self) -> None:
        # These ZIP paths may be generated by a dirty production checkout:
        # ZIP CRC and SHA integrity cannot authorize customer data release.
        unsafe_files = (
            "backend/public/storage",
            "backend/public/storage/tenant-2/private-avatar.png",
            "backend/public/uploads/tenant-1/invoice.pdf",
            "backend/Public/STORAGE/tenant-1/customer-photo.png",
            "public/storage/tenant-7/customer-export.csv",
            "public/uploads/tenant-1/attachment.pdf",
            "backend/uploads/customer-photo.jpg",
        )
        for entry in unsafe_files:
            with self.subTest(entry=entry):
                self.pack({entry: b"PRIVATE CUSTOMER DATA"})
                with self.assertRaisesRegex(SetupZipError, "Public uploaded/runtime content"):
                    self.verify()

        # Explicit directory members must not create or replace a live
        # public/storage link or public/uploads root during extraction.
        for directory in (
            "backend/public/storage/",
            "backend/public/uploads/",
            "public/storage/",
            "public/uploads/",
            "backend/uploads/",
        ):
            with self.subTest(directory=directory):
                self.pack()
                with zipfile.ZipFile(self.archive, "a") as archive:
                    member = zipfile.ZipInfo(directory)
                    member.external_attr = (stat.S_IFDIR | 0o755) << 16
                    archive.writestr(member, b"")
                with self.assertRaisesRegex(SetupZipError, "Public uploaded/runtime content"):
                    self.verify()

        # Preserve legitimate public code/assets and empty Laravel storage
        # directories, which are distinct from public uploaded content.
        self.pack({
            "backend/public/assets/app.js": b"const app = true;",
            "public/assets/app.css": b"body { margin: 0; }",
        })
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", self.verify()["status"])

    def test_empty_laravel_private_storage_directories_are_allowed_but_files_are_not(self) -> None:
        # Fresh Laravel expects these folders to exist, but no upload,
        # private key, cache/session or production log may be prepackaged.
        self.pack()
        with zipfile.ZipFile(self.archive, "a") as archive:
            for name in (
                "backend/storage/app/private/",
                "backend/storage/app/system/",
                "backend/storage/app/public/",
                "backend/storage/logs/",
            ):
                member = zipfile.ZipInfo(name)
                member.external_attr = (stat.S_IFDIR | 0o755) << 16
                archive.writestr(member, b"")
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", self.verify()["status"])

        for file in (
            "backend/storage/app/private/customer-upload.json",
            "backend/storage/app/public/customer-upload.json",
            "backend/storage/app/system/installed-secrets.json",
            "backend/storage/logs/laravel.log",
        ):
            with self.subTest(file=file):
                self.pack({file: b"private runtime data"})
                with self.assertRaisesRegex(SetupZipError, "Private|Runtime"):
                    self.verify()

    def test_runtime_sessions_cached_config_and_compiled_views_cannot_ship_in_setup(self) -> None:
        # These files might exist in a dirty host or CI working tree. An
        # APP_KEY, DB password, session cookie or compiled view must never
        # enter a public Setup archive merely because it passed zip CRC.
        unsafe = (
            "backend/storage/framework/sessions/abcd-session",
            "backend/storage/framework/cache/data/ab/secret.cache",
            "backend/storage/framework/views/compiled-view.php",
            "backend/storage/framework/testing/testing-session",
            "backend/bootstrap/cache/config.php",
            "backend/bootstrap/cache/routes-v7.php",
            "backend/bootstrap/cache/services.php",
            "backend/Storage/Framework/Sessions/CAPS-SESSION",
        )
        for entry in unsafe:
            with self.subTest(entry=entry):
                self.pack({entry: b"APP_KEY=base64:secret;DB_PASSWORD=secret"})
                with self.assertRaisesRegex(SetupZipError, "Runtime session/cache/config"):
                    self.verify()

    def test_empty_runtime_directories_are_preserved_without_private_files(self) -> None:
        self.pack()
        with zipfile.ZipFile(self.archive, "a") as archive:
            for directory in (
                "backend/storage/framework/sessions/",
                "backend/storage/framework/cache/",
                "backend/storage/framework/cache/data/",
                "backend/storage/framework/views/",
                "backend/storage/framework/testing/",
                "backend/bootstrap/cache/",
            ):
                member = zipfile.ZipInfo(directory)
                member.external_attr = (stat.S_IFDIR | 0o755) << 16
                archive.writestr(member, b"")
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", self.verify()["status"])
        # Public framework source remains packageable.
        self.pack({"backend/app/Http/Kernel.php": b"<?php"})
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", self.verify()["status"])

    def test_unicode_path_extra_cannot_override_safe_zip_member_name(self) -> None:
        # A valid ZIP central path could be extracted as a private .env file
        # if another extractor trusts Info-ZIP's 0x7075 alternate filename.
        # An intentionally bad CRC demonstrates Python ignores that alias.
        alternate = b"\x01" + (0).to_bytes(4, "little") + b"backend/.env.local"
        extra = (0x7075).to_bytes(2, "little") + len(alternate).to_bytes(2, "little") + alternate
        member = zipfile.ZipInfo("backend/public/safe.php")
        member.external_attr = (stat.S_IFREG | 0o644) << 16
        member.extra = extra
        with zipfile.ZipFile(self.archive, "a") as archive:
            archive.writestr(member, b"<?php // no secret")
        with zipfile.ZipFile(self.archive) as archive:
            self.assertEqual("backend/public/safe.php", archive.getinfo(member.filename).filename)
            self.assertIsNone(archive.testzip())
        with self.assertRaisesRegex(SetupZipError, "Ambiguous Unicode Path"):
            self.verify()

    def test_wrapped_setup_zip_is_rejected_even_when_zipfile_crc_passes(self) -> None:
        # A cPanel/shared-host extractor need not handle SFX stubs, trailing
        # bytes or comments as Python does. Reject ambiguous one-ZIP input.
        for wrapper in ("prepended", "appended", "comment"):
            with self.subTest(wrapper=wrapper):
                self.pack()
                if wrapper == "prepended":
                    self.archive.write_bytes(b"<?php exit; ?>" + self.archive.read_bytes())
                elif wrapper == "appended":
                    self.archive.write_bytes(self.archive.read_bytes() + b"<?php exit; ?>")
                else:
                    with zipfile.ZipFile(self.archive, "a") as archive:
                        archive.comment = b"extra payload"

                with zipfile.ZipFile(self.archive) as archive:
                    self.assertIsNone(archive.testzip())
                with self.assertRaisesRegex(SetupZipError, "prepended|appended|archive comment"):
                    self.verify()


    def test_stored_and_deflated_zip_members_are_portable(self) -> None:
        for method in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED):
            with self.subTest(method=method):
                self.pack()
                member = zipfile.ZipInfo("backend/public/portable-asset.js")
                member.external_attr = (stat.S_IFREG | 0o644) << 16
                with zipfile.ZipFile(self.archive, "a") as archive:
                    archive.writestr(member, b"const valid = true;", compress_type=method)
                with zipfile.ZipFile(self.archive) as archive:
                    self.assertIsNone(archive.testzip())
                    self.assertEqual(method, archive.getinfo(member.filename).compress_type)
                self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", self.verify()["status"])

    def test_bzip2_and_lzma_members_fail_even_when_python_can_extract_them(self) -> None:
        # Python's ZIP reader supports these, but cPanel/shared-host unzip
        # implementations are not guaranteed to: staging must fail closed.
        for method in (zipfile.ZIP_BZIP2, zipfile.ZIP_LZMA):
            with self.subTest(method=method):
                self.pack()
                member = zipfile.ZipInfo("backend/public/nonportable-asset.js")
                member.external_attr = (stat.S_IFREG | 0o644) << 16
                with zipfile.ZipFile(self.archive, "a") as archive:
                    archive.writestr(member, b"const nonportable = true;", compress_type=method)
                with zipfile.ZipFile(self.archive) as archive:
                    self.assertIsNone(archive.testzip())
                    self.assertEqual(method, archive.getinfo(member.filename).compress_type)
                with self.assertRaisesRegex(SetupZipError, "Unsupported Setup ZIP compression"):
                    self.verify()

    def test_crc_valid_conflicting_local_header_method_or_flags_fail_closed(self) -> None:
        # Python zipfile trusts central method/flags while reading payloads,
        # but a shared-host extractor may interpret the LOCAL header instead.
        for offset, forged, reason in (
            (8, zipfile.ZIP_BZIP2, "compression"),
            (6, 2, "flags"),
        ):
            with self.subTest(reason=reason):
                self.pack()
                payload = bytearray(self.archive.read_bytes())
                local = payload.index(b"PK\x03\x04")
                payload[local + offset:local + offset + 2] = forged.to_bytes(2, "little")
                self.archive.write_bytes(payload)
                with zipfile.ZipFile(self.archive) as archive:
                    self.assertIsNone(archive.testzip())
                with self.assertRaisesRegex(SetupZipError, "Conflicting local/central Setup ZIP " + reason):
                    self.verify()

    def test_streaming_zip_data_descriptors_are_consistent_before_extraction(self) -> None:
        # A non-seekable ZIP writer emits flag 0x08 and an actual post-data
        # descriptor. Python's testzip() never verifies its CRC/size fields.
        class NonSeekable(io.BytesIO):
            def seek(self, *args: object, **kwargs: object) -> int:
                raise io.UnsupportedOperation("non-seekable output")

        def pack_streaming_zip() -> None:
            output = NonSeekable()
            with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                for name, payload in BASE_ENTRIES.items():
                    member = zipfile.ZipInfo(name)
                    member.external_attr = (stat.S_IFREG | 0o644) << 16
                    archive.writestr(member, payload, compress_type=zipfile.ZIP_DEFLATED)
            self.archive.write_bytes(output.getvalue())

        pack_streaming_zip()
        with zipfile.ZipFile(self.archive) as archive:
            self.assertTrue(all(member.flag_bits & 0x08 for member in archive.infolist()))
            self.assertIsNone(archive.testzip())
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", self.verify()["status"])

        for offset, field in ((4, "CRC"), (8, "compressed size"),
                              (12, "uncompressed size")):
            with self.subTest(field=field):
                pack_streaming_zip()
                payload = bytearray(self.archive.read_bytes())
                descriptor_start = payload.index(b"PK\x07\x08")
                original = int.from_bytes(
                    payload[descriptor_start + offset:descriptor_start + offset + 4],
                    "little",
                )
                payload[descriptor_start + offset:descriptor_start + offset + 4] = (
                    original ^ 1
                ).to_bytes(4, "little")
                self.archive.write_bytes(payload)
                with zipfile.ZipFile(self.archive) as archive:
                    self.assertIsNone(archive.testzip())
                with self.assertRaisesRegex(
                    SetupZipError, "Conflicting Setup ZIP data descriptor"
                ):
                    self.verify()

    def test_reserved_general_purpose_flags_fail_even_when_zipfile_crc_passes(self) -> None:
        # Matching CENTRAL and LOCAL flags can still be unportable: Python
        # ignores these unsupported bits, but shared-host unzip tools need not.
        for unsupported_bit in (0x0010, 0x2000):
            with self.subTest(unsupported_bit=hex(unsupported_bit)):
                self.pack()
                payload = bytearray(self.archive.read_bytes())
                local = payload.index(b"PK\x03\x04")
                central = payload.index(b"PK\x01\x02")
                for flag_offset in (local + 6, central + 8):
                    flags = int.from_bytes(payload[flag_offset:flag_offset + 2], "little")
                    payload[flag_offset:flag_offset + 2] = (
                        flags | unsupported_bit
                    ).to_bytes(2, "little")
                self.archive.write_bytes(payload)
                with zipfile.ZipFile(self.archive) as archive:
                    self.assertIsNone(archive.testzip())
                with self.assertRaisesRegex(
                    SetupZipError, "Unsupported Setup ZIP general-purpose flags"
                ):
                    self.verify()

    def test_crc_valid_conflicting_local_header_crc_or_sizes_fail_closed(self) -> None:
        # Python reads integrity fields from CENTRAL metadata and accepts
        # these CRC-valid ZIPs, but hosting extractors may trust LOCAL values.
        for offset, field in ((14, "CRC"), (18, "sizes"), (22, "sizes")):
            with self.subTest(local_header_offset=offset):
                self.pack()
                payload = bytearray(self.archive.read_bytes())
                local = payload.index(b"PK\x03\x04")
                original = int.from_bytes(payload[local + offset:local + offset + 4], "little")
                payload[local + offset:local + offset + 4] = (original ^ 1).to_bytes(4, "little")
                self.archive.write_bytes(payload)
                with zipfile.ZipFile(self.archive) as archive:
                    self.assertIsNone(archive.testzip())
                with self.assertRaisesRegex(
                    SetupZipError, "Conflicting local/central Setup ZIP " + field
                ):
                    self.verify()

    def test_unicode_path_alias_in_only_local_zip_header_is_rejected(self) -> None:
        # Central extra metadata is harmless after rewriting its field ID,
        # but the *local* entry retains a Unicode Path override that another
        # extractor may use as backend/.env.local; Python CRC still passes.
        alias = b"\x01" + (0).to_bytes(4, "little") + b"backend/.env.local"
        extra = (0x7075).to_bytes(2, "little") + len(alias).to_bytes(2, "little") + alias
        member = zipfile.ZipInfo("backend/public/safe-extra.php")
        member.external_attr = (stat.S_IFREG | 0o644) << 16
        member.extra = extra
        with zipfile.ZipFile(self.archive, "a") as archive:
            archive.writestr(member, b"<?php // safe")
        payload = bytearray(self.archive.read_bytes())
        central = payload.rfind(b"PK\x01\x02")
        self.assertGreaterEqual(central, 0)
        filename_size = int.from_bytes(payload[central + 28:central + 30], "little")
        central_extra = central + 46 + filename_size
        self.assertEqual(0x7075, int.from_bytes(payload[central_extra:central_extra + 2], "little"))
        payload[central_extra:central_extra + 2] = (0x5455).to_bytes(2, "little")
        self.archive.write_bytes(payload)
        with zipfile.ZipFile(self.archive) as archive:
            self.assertEqual(0x5455, int.from_bytes(
                archive.getinfo(member.filename).extra[:2], "little"))
            self.assertIsNone(archive.testzip())
        with self.assertRaisesRegex(SetupZipError, "Ambiguous Unicode Path"):
            self.verify()

    def test_safe_extra_timestamp_metadata_does_not_block_staging_zip(self) -> None:
        member = zipfile.ZipInfo("backend/public/normal.php")
        member.external_attr = (stat.S_IFREG | 0o644) << 16
        member.extra = (0x5455).to_bytes(2, "little") + (1).to_bytes(2, "little") + b"\x00"
        with zipfile.ZipFile(self.archive, "a") as archive:
            archive.writestr(member, b"<?php // normal")
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", self.verify()["status"])

    def test_malformed_extra_field_length_fails_closed(self) -> None:
        member = zipfile.ZipInfo("backend/public/unsafe.php")
        member.external_attr = (stat.S_IFREG | 0o644) << 16
        member.extra = b"\x34\x12\x03\x00\x01"
        with zipfile.ZipFile(self.archive, "a") as archive:
            archive.writestr(member, b"<?php")
        with self.assertRaisesRegex(SetupZipError, "Malformed|invalid Setup ZIP"):
            self.verify()

    def test_unsafe_cross_platform_member_names_rejected(self) -> None:
        for entry in (
            "../outside.php",
            "backend/../outside.php",
            "backend\\app\\escape.php",
            "/etc/passwd",
            "C:/Windows/system.ini",
            "backend/NUL.txt",
            "backend/CONIN$.txt",
            "backend/lpt².log",
            "backend/name:stream",
            "backend/trailing.",
            "backend/bad\tname.php",
        ):
            with self.subTest(entry=entry):
                self.pack({entry: b"unsafe"})
                with self.assertRaises(SetupZipError):
                    self.verify()

    def test_unicode_and_case_duplicate_targets_rejected(self) -> None:
        for left, right in (
            ("backend/public/café.txt", "backend/public/cafe\u0301.txt"),
            ("backend/public/Foo.txt", "backend/public/foo.txt"),
        ):
            with self.subTest(left=left, right=right):
                self.pack({left: b"one", right: b"two"})
                with self.assertRaisesRegex(SetupZipError, "Duplicate"):
                    self.verify()

    def test_regular_file_cannot_also_be_parent_directory(self) -> None:
        self.pack({
            "backend/public/asset": b"regular file",
            "backend/public/asset/index.php": b"<?php",
        })
        with self.assertRaisesRegex(SetupZipError, "path collision"):
            self.verify()

    def test_symlink_and_nonregular_members_rejected(self) -> None:
        for mode in (stat.S_IFLNK | 0o777, stat.S_IFCHR | 0o666):
            with self.subTest(mode=mode):
                self.pack({"backend/public/external": b"target"},
                          {"backend/public/external": mode})
                with self.assertRaisesRegex(SetupZipError, "Symlink|Non-regular"):
                    self.verify()

    def test_oversize_and_member_budget_fails_before_decompression(self) -> None:
        with patch("scripts.v2_setup_staging_verify.MAX_ENTRY_SIZE", 4):
            with self.assertRaisesRegex(SetupZipError, "Oversize"):
                self.verify()
        with patch("scripts.v2_setup_staging_verify.MAX_ENTRIES", 3):
            with self.assertRaisesRegex(SetupZipError, "member count"):
                self.verify()

    def test_missing_version_and_bad_exact_source_identity_rejected(self) -> None:
        self.pack({"VERSION": b"1.0.126"})
        with self.assertRaisesRegex(SetupZipError, "VERSION differs"):
            self.verify()
        self.pack()
        with self.assertRaisesRegex(SetupZipError, "Invalid exact source SHA"):
            verify_setup_zip(self.archive, self.version, "not-a-git-sha")
        self.version.write_text("١.0.125", encoding="utf-8")
        with self.assertRaisesRegex(SetupZipError, "Invalid source Semantic Version"):
            self.verify()


    def test_setup_env_template_allows_release_contract_defaults_with_empty_secrets(self) -> None:
        payload = (
            BASE_ENTRIES["backend/.env.production.example"]
            + b"APP_URL=https://foodex.50sols.com\n"
            + b"DB_DATABASE=solscool_foodex\n"
            + b"DB_USERNAME=solscool_foodex\n"
            + b"MAIL_FROM_ADDRESS=noreply@50sols.com\n"
        )
        self.pack({"backend/.env.production.example": payload})
        self.verify()

    def test_setup_env_template_rejects_nonempty_secret_values(self) -> None:
        for value in (
            "APP_KEY=base64:must-not-ship",
            "DB_PASSWORD=must-not-ship",
            "MAIL_PASSWORD=must-not-ship",
            "REDIS_PASSWORD=must-not-ship",
            "AWS_SECRET_ACCESS_KEY=must-not-ship",
        ):
            with self.subTest(value=value):
                payload = BASE_ENTRIES["backend/.env.production.example"] + (value + "\\n").encode()
                self.pack({"backend/.env.production.example": payload})
                with self.assertRaisesRegex(SetupZipError, "environment template contains non-empty secret fields"):
                    self.verify()

    def test_install_instructions_reject_production_host_and_database_values(self) -> None:
        for value in ("foodex.50sols.com", "solscool_vanfoodex"):
            with self.subTest(value=value):
                self.pack({
                    "INSTALL-FIRST.txt": BASE_ENTRIES["INSTALL-FIRST.txt"] + value.encode("utf-8"),
                })
                with self.assertRaisesRegex(SetupZipError, "deployment-specific production values"):
                    self.verify()

    def test_install_instructions_require_cpanel_runtime_and_scheduler_guidance(self) -> None:
        self.pack({"INSTALL-FIRST.txt": b"Read instructions."})
        with self.assertRaisesRegex(SetupZipError, "omit cPanel/PHP/storage/scheduler guidance"):
            self.verify()

if __name__ == "__main__":
    unittest.main()
