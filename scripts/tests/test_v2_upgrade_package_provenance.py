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
                info.external_attr = ((stat.S_IFLNK | 0o777) if symlink else
                                      (stat.S_IFREG | 0o644)) << 16
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

    def test_zip_payload_modification_cannot_reuse_manifest_digest(self) -> None:
        with (self.first / "FOODEX-Update.zip").open("ab") as fp:
            fp.write(b"tampered after hashing")
        with self.assertRaisesRegex(PackageContractError, "ZIP SHA"):
            self.verify_first()


if __name__ == "__main__":
    unittest.main()
