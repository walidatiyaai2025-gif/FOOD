"""Isolated adversarial tests for nonpublishing V2 Setup ZIP verification."""
from __future__ import annotations

import hashlib
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
    "INSTALL-FIRST.txt": b"Read instructions.",
    "backend/public/index.php": b"<?php",
    "backend/vendor/autoload.php": b"<?php",
    "backend/.env.production.example": b"APP_ENV=production\n",
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

    def test_secret_env_and_runtime_private_members_fail_closed(self) -> None:
        for entry in (
            "backend/.env",
            "backend/.env.local",
            "backend/.env.production",
            "backend/.env.testing",
            "backend/vendor/pkg/.ENV.production",
            "backend/storage/app/private/key.json",
            "backend/storage/app/system/settings.json",
            "backend/storage/logs/laravel.log",
            "backend/.git/config",
        ):
            with self.subTest(entry=entry):
                self.pack({entry: b"secret"})
                with self.assertRaisesRegex(SetupZipError, "Secret|Private|Runtime|Source control"):
                    self.verify()

    def test_empty_laravel_private_storage_directories_are_allowed_but_files_are_not(self) -> None:
        # Fresh Laravel expects these folders to exist, but no upload,
        # private key, cache/session or production log may be prepackaged.
        self.pack()
        with zipfile.ZipFile(self.archive, "a") as archive:
            for name in (
                "backend/storage/app/private/",
                "backend/storage/app/system/",
                "backend/storage/logs/",
            ):
                member = zipfile.ZipInfo(name)
                member.external_attr = (stat.S_IFDIR | 0o755) << 16
                archive.writestr(member, b"")
        self.assertEqual("UNTRUSTED_CI_STAGING_ONLY", self.verify()["status"])

        for file in (
            "backend/storage/app/private/customer-upload.json",
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


if __name__ == "__main__":
    unittest.main()
