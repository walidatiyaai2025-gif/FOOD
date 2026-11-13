"""Hermetic negative tests for the real ZIP preflight used by v125/v127 MySQL CI."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
GUARD = ROOT / "scripts/verify-recovery-update-manifest.py"


class RecoveryUpdateManifestPreflightTest(unittest.TestCase):
    def setUp(self) -> None:
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.package = self.root / "FOODEX-Update.zip"
        self.manifest_path = self.root / "FOODEX-Update.json"
        self.write_zip("version-125")
        self.manifest = self.valid_manifest()
        self.write_manifest()

    def write_zip(self, content: str) -> None:
        with zipfile.ZipFile(self.package, "w", compression=zipfile.ZIP_STORED) as bundle:
            bundle.writestr("VERSION", content)

    def valid_manifest(self) -> dict:
        digest = hashlib.sha256(self.package.read_bytes()).hexdigest()
        return {
            "schema_version": 1,
            "available": True,
            "requires_full_redeploy": False,
            "package": self.package.name,
            "target_version": "1.0.125",
            "minimum_current_version": "1.0.124",
            "sha256": digest,
            "dashboard_fields": {
                "target_version": "1.0.125",
                "minimum_current_version": "1.0.124",
                "sha256": digest,
            },
        }

    def write_manifest(self) -> None:
        self.manifest_path.write_text(json.dumps(self.manifest), encoding="utf-8")

    def invoke(self, target: str = "1.0.125", minimum: str = "1.0.124") -> subprocess.CompletedProcess:
        return subprocess.run(
            [
                sys.executable, str(GUARD),
                "--package", str(self.package),
                "--manifest", str(self.manifest_path),
                "--target-version", target,
                "--minimum-current-version", minimum,
            ],
            capture_output=True,
            text=True,
            check=False,
        )

    def test_matching_real_zip_manifest_is_accepted(self) -> None:
        result = self.invoke()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("PASS:", result.stdout)

    def test_package_content_changes_are_rejected_before_overlay(self) -> None:
        self.write_zip("tampered-version-without-updated-manifest")
        result = self.invoke()
        self.assertNotEqual(0, result.returncode)
        self.assertIn("SHA-256 mismatch", result.stderr)

    def test_wrong_release_identity_is_rejected(self) -> None:
        for key, value, expected_error in (
            ("target_version", "1.0.127", "target version mismatch"),
            ("minimum_current_version", "1.0.123", "Unsupported installed version"),
            ("package", "Different-Update.zip", "filename differs"),
        ):
            with self.subTest(key=key):
                self.manifest = self.valid_manifest()
                self.manifest[key] = value
                self.write_manifest()
                self.assertIn(expected_error, self.invoke().stderr)

    def test_declared_but_wrong_dashboard_metadata_is_rejected(self) -> None:
        for key, value in (
            ("target_version", "1.0.127"),
            ("minimum_current_version", "1.0.123"),
            ("sha256", "0" * 64),
        ):
            with self.subTest(key=key):
                self.manifest = self.valid_manifest()
                self.manifest["dashboard_fields"][key] = value
                self.write_manifest()
                self.assertIn("Conflicting Dashboard update metadata", self.invoke().stderr)

    def test_invalid_manifest_schema_and_state_are_rejected(self) -> None:
        for key, value in (
            ("schema_version", 99),
            ("schema_version", True),
            ("available", False),
            ("requires_full_redeploy", True),
            ("sha256", "untrusted"),
        ):
            with self.subTest(key=key, value=value):
                self.manifest = self.valid_manifest()
                self.manifest[key] = value
                self.write_manifest()
                self.assertNotEqual(0, self.invoke().returncode)

    def test_missing_and_corrupt_manifest_fail_closed(self) -> None:
        self.manifest_path.unlink()
        self.assertIn("manifest is unavailable or invalid", self.invoke().stderr)
        self.manifest_path.write_text("not JSON", encoding="utf-8")
        self.assertIn("manifest is unavailable or invalid", self.invoke().stderr)

    def test_missing_invalid_and_empty_zip_fail_closed(self) -> None:
        self.package.unlink()
        self.assertIn("Missing or empty update ZIP", self.invoke().stderr)
        self.package.write_bytes(b"")
        self.assertIn("Missing or empty update ZIP", self.invoke().stderr)
        self.package.write_bytes(b"random bytes not a zip")
        self.assertIn("not a valid ZIP", self.invoke().stderr)

    def test_both_real_upgrade_scripts_require_preflight_before_any_unzip(self) -> None:
        for name, target, minimum in (
            ("ci-125-recovery-upgrade.sh", "1.0.125", "1.0.124"),
            ("ci-127-upgrade.sh", "1.0.127", "1.0.125"),
        ):
            with self.subTest(name=name):
                script = (ROOT / "scripts" / name).read_text(encoding="utf-8")
                self.assertIn("verify-recovery-update-manifest.py", script)
                self.assertIn(f"--target-version {target}", script)
                self.assertIn(f"--minimum-current-version {minimum}", script)
                self.assertLess(
                    script.index("verify-recovery-update-manifest.py"),
                    script.index('unzip -q -o "$package"'),
                )

    def test_two_historical_versions_remain_independent(self) -> None:
        self.manifest["target_version"] = "1.0.127"
        self.manifest["minimum_current_version"] = "1.0.125"
        self.manifest["dashboard_fields"]["target_version"] = "1.0.127"
        self.manifest["dashboard_fields"]["minimum_current_version"] = "1.0.125"
        self.write_manifest()
        self.assertEqual(0, self.invoke("1.0.127", "1.0.125").returncode)
        self.assertNotEqual(0, self.invoke("1.0.125", "1.0.124").returncode)


if __name__ == "__main__":
    unittest.main()
