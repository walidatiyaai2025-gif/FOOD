"""Tests for G006 archive integrity; synthetic ZIPs, no releases or live data."""
from __future__ import annotations

import contextlib
import io
import json
import stat
import tempfile
import unittest
import warnings
import zipfile
from pathlib import Path

from scripts.v2_upgrade_package_provenance import (
    PackageContractError,
    main,
    validate_package,
)


SHA125 = "a" * 40
SHA127 = "b" * 40
BASE = "c" * 40
MIGRATION = "backend/database/migrations/2026_11_08_070000_test.php"
RUNTIME = "backend/app/Domain/Updater/LaravelUpdateRuntime.php"


def build_bundle(
    parent: Path,
    *,
    old: str = "1.0.124",
    new: str = "1.0.125",
    source: str = SHA125,
    entries: list[tuple[str, bytes, bool]] | None = None,
    redeploy: bool = False,
    special_mode: int | None = None,
) -> Path:
    parent.mkdir(parents=True, exist_ok=True)
    if entries is None:
        entries = [
            ("VERSION", new.encode(), False),
            (RUNTIME, b"<?php echo 'test';", False),
            (MIGRATION, b"<?php // migration", False),
        ]
    archive_path = parent / "FOODEX-Update.zip"
    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            for name, content, symlink in entries:
                info = zipfile.ZipInfo(name)
                mode = ((stat.S_IFLNK | 0o777) if symlink else
                        (stat.S_IFREG | 0o644))
                if special_mode is not None and name == RUNTIME:
                    mode = special_mode
                info.external_attr = mode << 16
                archive.writestr(info, content)
    from hashlib import sha256
    digest = sha256(archive_path.read_bytes()).hexdigest()
    names = [name for name, _, _ in entries]
    migrations = [name for name in names if name.startswith("backend/database/migrations/")]
    manifest = {
        "schema_version": 1,
        "available": True,
        "package": archive_path.name,
        "target_version": new,
        "minimum_current_version": old,
        "source_base": BASE,
        "source_commit": source,
        "sha256": digest,
        "contains_migrations": bool(migrations),
        "migrations": migrations,
        "requires_full_redeploy": redeploy,
        "delta": {"file_count": len(names), "files_manifest": "FOODEX-Update.files.txt"},
        "dashboard_fields": {"source_commit": source, "sha256": digest},
    }
    (parent / "FOODEX-Update.json").write_text(json.dumps(manifest))
    (parent / "FOODEX-Update.sha256.txt").write_text(f"{digest}  FOODEX-Update.zip\n")
    (parent / "FOODEX-Update.files.txt").write_text("\n".join(names) + "\n")
    return parent


class V2G006PackageProvenanceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.first = build_bundle(self.root / "124-125")

    def verify_first(self) -> dict[str, str | int]:
        return validate_package(
            self.first,
            installed_version="1.0.124",
            target_version="1.0.125",
            expected_source_commit=SHA125,
        )

    def change_manifest(self, key: str, value: object) -> None:
        path = self.first / "FOODEX-Update.json"
        manifest = json.loads(path.read_text())
        manifest[key] = value
        path.write_text(json.dumps(manifest))

    def test_genuine_matching_zip_manifest_checksum_file_list_and_version_pass(self) -> None:
        report = self.verify_first()
        self.assertEqual("1.0.125", report["to"])
        self.assertEqual(SHA125, report["source_commit"])
        self.assertEqual(3, report["files"])

    def test_exact_historical_pair_cli_accepts_only_124_125_and_125_127(self) -> None:
        second = build_bundle(
            self.root / "125-127", old="1.0.125", new="1.0.127", source=SHA127,
        )
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(0, main([
                "--upgrade-124-125", str(self.first),
                "--upgrade-125-127", str(second),
                "--source-125", SHA125,
                "--source-127", SHA127,
            ]))
        result = json.loads(output.getvalue())
        self.assertEqual("archive_integrity_only", result["status"])
        self.assertEqual(["1.0.125", "1.0.127"],
                         [row["to"] for row in result["verified_edges"]])

    def test_symlinked_package_directory_is_rejected_before_reading_assets(self) -> None:
        alias = self.root / "linked-124-125"
        try:
            alias.symlink_to(self.first, target_is_directory=True)
        except (OSError, NotImplementedError):
            self.skipTest("Filesystem does not support symlinks")

        with self.assertRaisesRegex(PackageContractError, "symbolic link"):
            validate_package(
                alias,
                installed_version="1.0.124",
                target_version="1.0.125",
                expected_source_commit=SHA125,
            )

    def test_symlinked_parent_of_real_package_directory_is_rejected(self) -> None:
        actual_parent = self.root / "actual-parent"
        build_bundle(actual_parent / "real-bundle")
        linked_parent = self.root / "linked-parent"
        try:
            linked_parent.symlink_to(actual_parent, target_is_directory=True)
        except (OSError, NotImplementedError):
            self.skipTest("Filesystem does not support symlinks")

        candidate = linked_parent / "real-bundle"
        self.assertTrue(candidate.is_dir())
        self.assertFalse(candidate.is_symlink())
        with self.assertRaisesRegex(PackageContractError, "symbolic link"):
            validate_package(
                candidate,
                installed_version="1.0.124",
                target_version="1.0.125",
                expected_source_commit=SHA125,
            )

    def test_symlinked_zip_manifest_checksum_or_file_list_is_rejected(self) -> None:
        for name in (
            "FOODEX-Update.zip",
            "FOODEX-Update.json",
            "FOODEX-Update.sha256.txt",
            "FOODEX-Update.files.txt",
        ):
            with self.subTest(artifact=name):
                artifact = self.first / name
                original = artifact.read_bytes()
                foreign = self.root / ("foreign-" + name)
                foreign.write_bytes(original)
                artifact.unlink()
                try:
                    artifact.symlink_to(foreign)
                except (OSError, NotImplementedError):
                    artifact.write_bytes(original)
                    self.skipTest("Filesystem does not support symlinks")

                with self.assertRaisesRegex(PackageContractError, "Symbolic link artifact"):
                    self.verify_first()
                artifact.unlink()
                artifact.write_bytes(original)

    def test_reject_non_ascii_and_leading_zero_manifest_version_edges(self) -> None:
        # The offline verifier must agree with the Laravel updater's strict
        # ASCII, no-leading-zero canonical SemVer trust boundary.
        malformed = (
            "01.0.125", "1.00.125", "1.0.0125",
            "١.0.125", "1.٠.125", "1.0.１２５",
            " 1.0.125", "1.0.125 ", "1.0.125\n",
        )
        for value in malformed:
            with self.subTest(target=value):
                with self.assertRaisesRegex(PackageContractError, "Invalid semantic-version edge"):
                    validate_package(
                        self.first, installed_version="1.0.124",
                        target_version=value, expected_source_commit=SHA125,
                    )

        malformed_installed = (
            "01.0.124", "1.00.124", "1.0.0124",
            "١.0.124", "1.٠.124", "1.0.１２４",
        )
        for value in malformed_installed:
            with self.subTest(installed=value):
                with self.assertRaisesRegex(PackageContractError, "Invalid semantic-version edge"):
                    validate_package(
                        self.first, installed_version=value,
                        target_version="1.0.125", expected_source_commit=SHA125,
                    )
        self.assertEqual("1.0.125", self.verify_first()["to"])

    def test_bad_upgrade_edge_and_redeploy_flag_fail_closed(self) -> None:
        self.change_manifest("minimum_current_version", "1.0.123")
        with self.assertRaisesRegex(PackageContractError, "upgrade edge"):
            self.verify_first()
        self.first = build_bundle(self.first, redeploy=True)
        with self.assertRaisesRegex(PackageContractError, "full redeploy"):
            self.verify_first()

    def test_manifest_hash_checksum_and_source_mismatch_fail(self) -> None:
        self.change_manifest("sha256", "0" * 64)
        with self.assertRaisesRegex(PackageContractError, "ZIP SHA"):
            self.verify_first()
        self.first = build_bundle(self.first)
        (self.first / "FOODEX-Update.sha256.txt").write_text("0" * 64 + "  FOODEX-Update.zip\n")
        with self.assertRaisesRegex(PackageContractError, "Standalone"):
            self.verify_first()
        self.first = build_bundle(self.first)
        with self.assertRaisesRegex(PackageContractError, "Source SHA"):
            validate_package(
                self.first, installed_version="1.0.124",
                target_version="1.0.125", expected_source_commit=SHA127,
            )

    def test_alias_duplicate_entries_rejected_even_with_valid_hash(self) -> None:
        self.first = build_bundle(
            self.first,
            entries=[
                ("VERSION", b"1.0.125", False),
                ("backend/example.php", b"one", False),
                ("backend\\example.php", b"two", False),
            ],
        )
        with self.assertRaisesRegex(PackageContractError, "Duplicate or aliased"):
            self.verify_first()

    def test_casefold_and_unicode_normalized_archive_aliases_fail_closed(self) -> None:
        # A future updater can extract onto a case-insensitive or NFD volume;
        # different ZIP names must not resolve to one target on those hosts.
        for first, second in (
            ("backend/Catalog.php", "backend/catalog.php"),
            ("backend/café.php", "backend/cafe\u0301.php"),
        ):
            with self.subTest(first=first, second=second):
                self.first = build_bundle(
                    self.first,
                    entries=[
                        ("VERSION", b"1.0.125", False),
                        (first, b"one", False),
                        (second, b"two", False),
                    ],
                )
                with self.assertRaisesRegex(PackageContractError, "Duplicate or aliased"):
                    self.verify_first()

    def test_portable_windows_path_and_case_insensitive_private_names_are_rejected(self) -> None:
        # Reject NTFS alternate data streams, reserved device paths and names
        # that Windows trims/collapses. Private files must be case-insensitive.
        for forbidden in (
            "backend/Config.php:stream",
            "backend/CON",
            "backend/NuL.txt",
            "backend/COM1.log",
            "backend/LPT9",
            "backend/file.php.",
            "backend/file.php ",
            "backend/bad\tname.php",
            "backend/name?.php",
            "backend/.ENV",
            "BACKEND/.EnV",
            "backend/Storage/private.txt",
            "Backend/Tests/Feature/HiddenTest.php",
        ):
            with self.subTest(path=forbidden):
                self.first = build_bundle(
                    self.first,
                    entries=[
                        ("VERSION", b"1.0.125", False),
                        (forbidden, b"unsafe", False),
                    ],
                )
                with self.assertRaisesRegex(PackageContractError, "Unsafe cross-platform|Private/non-runtime"):
                    self.verify_first()

    def test_safe_portable_update_member_paths_remain_accepted(self) -> None:
        self.first = build_bundle(
            self.first,
            entries=[
                ("VERSION", b"1.0.125", False),
                (RUNTIME, b"<?php echo 'test';", False),
                ("backend/public/.htaccess", b"Options -Indexes", False),
                (MIGRATION, b"<?php // migration", False),
            ],
        )
        self.assertEqual(4, self.verify_first()["files"])

    def test_exact_duplicate_entries_rejected(self) -> None:
        self.first = build_bundle(
            self.first,
            entries=[
                ("VERSION", b"1.0.125", False),
                ("backend/example.php", b"one", False),
                ("backend/example.php", b"two", False),
            ],
        )
        with self.assertRaisesRegex(PackageContractError, "Duplicate or aliased"):
            self.verify_first()

    def test_non_regular_unix_member_modes_are_rejected(self) -> None:
        # ZIP member names without trailing slashes can still declare FIFO,
        # device, socket or directory types in Unix external attributes.
        for file_type in (
            stat.S_IFIFO, stat.S_IFCHR, stat.S_IFBLK,
            stat.S_IFSOCK, stat.S_IFDIR,
        ):
            with self.subTest(file_type=file_type):
                self.first = build_bundle(
                    self.first, special_mode=file_type | 0o644,
                )
                with self.assertRaisesRegex(PackageContractError, "Non-regular"):
                    self.verify_first()
        self.first = build_bundle(self.first)
        self.assertEqual("1.0.125", self.verify_first()["to"])

    def test_traversal_symlink_secret_and_non_runtime_paths_rejected(self) -> None:
        for forbidden in ("../outside.php", "backend/../outside.php", "backend/.env",
                          "backend/storage/app/private.txt", "apps/driver/app.apk"):
            with self.subTest(path=forbidden):
                self.first = build_bundle(
                    self.first,
                    entries=[("VERSION", b"1.0.125", False),
                             (forbidden, b"secret", False)],
                )
                with self.assertRaisesRegex(PackageContractError, "Unsafe|Private"):
                    self.verify_first()
        self.first = build_bundle(
            self.first,
            entries=[("VERSION", b"1.0.125", False),
                     ("backend/link.php", b"/etc/passwd", True)],
        )
        with self.assertRaisesRegex(PackageContractError, "Symlink"):
            self.verify_first()

    def test_zip_member_version_list_and_migrations_must_match_manifest(self) -> None:
        self.first = build_bundle(
            self.first, new="1.0.126",
        )
        self.change_manifest("target_version", "1.0.125")
        with self.assertRaisesRegex(PackageContractError, "ZIP VERSION"):
            self.verify_first()
        self.first = build_bundle(self.first)
        (self.first / "FOODEX-Update.files.txt").write_text("VERSION\n")
        with self.assertRaisesRegex(PackageContractError, "order/list"):
            self.verify_first()
        self.first = build_bundle(self.first)
        self.change_manifest("contains_migrations", False)
        with self.assertRaisesRegex(PackageContractError, "migration-presence"):
            self.verify_first()

    def test_manifest_migrations_cannot_omit_or_repeat_zip_members(self) -> None:
        second_migration = "backend/database/migrations/2026_11_09_070000_second.php"
        self.first = build_bundle(
            self.first,
            entries=[
                ("VERSION", b"1.0.125", False),
                (RUNTIME, b"<?php echo 'test';", False),
                (MIGRATION, b"<?php // first", False),
                (second_migration, b"<?php // second", False),
            ],
        )
        self.change_manifest("migrations", [MIGRATION])
        with self.assertRaisesRegex(PackageContractError, "Declared migrations"):
            self.verify_first()

        self.change_manifest("migrations", [MIGRATION, MIGRATION])
        with self.assertRaisesRegex(PackageContractError, "Declared migrations"):
            self.verify_first()

        self.change_manifest("migrations", [MIGRATION, second_migration])
        self.assertEqual(4, self.verify_first()["files"])

    def test_duplicate_manifest_keys_are_rejected_at_every_nesting_level(self) -> None:
        manifest_path = self.first / "FOODEX-Update.json"
        original = manifest_path.read_text(encoding="utf-8")
        for field in ('"available": true', '"file_count": 3', '"source_commit": "' + SHA125 + '"'):
            with self.subTest(duplicate=field):
                self.assertIn(field, original)
                ambiguous = original.replace(field, field + ", " + field, 1)
                manifest_path.write_text(ambiguous, encoding="utf-8")
                with self.assertRaisesRegex(PackageContractError, "Duplicate update manifest JSON key"):
                    self.verify_first()
        manifest_path.write_text(original, encoding="utf-8")
        self.assertEqual("1.0.125", self.verify_first()["to"])

    def test_non_finite_manifest_json_numbers_fail_closed(self) -> None:
        manifest_path = self.first / "FOODEX-Update.json"
        original = manifest_path.read_text(encoding="utf-8")
        self.assertIn('"file_count": 3', original)
        for constant in ("NaN", "Infinity", "-Infinity", "1e999", "-1e999"):
            with self.subTest(constant=constant):
                malformed = original.replace('"file_count": 3', '"file_count": ' + constant, 1)
                manifest_path.write_text(malformed, encoding="utf-8")
                with self.assertRaisesRegex(PackageContractError, "Non-finite update manifest JSON"):
                    self.verify_first()
        manifest_path.write_text(original, encoding="utf-8")
        self.assertEqual(3, self.verify_first()["files"])

    def test_malformed_dashboard_fields_are_rejected_not_interpreted(self) -> None:
        self.change_manifest("dashboard_fields", ["not-a-mapping"])
        with self.assertRaisesRegex(PackageContractError, "Dashboard fields"):
            self.verify_first()

    def test_zip_payload_modification_cannot_reuse_manifest_digest(self) -> None:
        with (self.first / "FOODEX-Update.zip").open("ab") as fp:
            fp.write(b"tampered after hashing")
        with self.assertRaisesRegex(PackageContractError, "ZIP SHA"):
            self.verify_first()


if __name__ == "__main__":
    unittest.main()
