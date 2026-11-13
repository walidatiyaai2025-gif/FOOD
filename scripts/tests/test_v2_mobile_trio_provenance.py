"""Negative regressions for FOODEX V2 G008 exact-head Android CI provenance."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from typing import Any

SCRIPT = Path(__file__).resolve().parents[1] / "v2-mobile-trio-provenance.py"
SPEC = importlib.util.spec_from_file_location("v2_mobile_trio_provenance", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)

HEAD = "a" * 40
BUILD = 42
PLATFORM_VERSION = "1.0.127"
MOBILE_VERSION = "1.0.127"


class V2MobileTrioEvidenceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.paths: dict[str, tuple[Path, Path]] = {}
        for app, identity in module.APPS.items():
            directory = self.root / f"v2-trio-{app}-{HEAD}"
            directory.mkdir()
            filename = f"foodex-{app}-android-{MOBILE_VERSION}+{BUILD}-{HEAD[:12]}-validation.apk"
            apk = directory / filename
            payload = f"synthetic-ci-apk-{app}".encode("ascii")
            apk.write_bytes(payload)
            digest = hashlib.sha256(payload).hexdigest()
            manifest: dict[str, Any] = {
                "schema_version": 1,
                "app": app,
                "platform": "android",
                "source_commit": HEAD,
                "platform_version": PLATFORM_VERSION,
                "mobile_version": MOBILE_VERSION,
                "build_number": str(BUILD),
                "build_mode": "release",
                "purpose": "ci-validation-only",
                "production_ready": False,
                "api_base_url": "https://foodex.50sols.com",
                "native_identity": identity,
                "signing": "flutter-template-debug-key",
                "artifact": filename,
                "bytes": len(payload),
                "sha256": digest,
            }
            (directory / "manifest.json").write_text(json.dumps(manifest), encoding="utf-8")
            (directory / "SHA256SUMS").write_text(f"{digest}  {filename}\n", encoding="utf-8")
            (directory / "README.txt").write_text("CI-only generated debug-key APK", encoding="utf-8")
            self.paths[app] = (directory, apk)

    def check(self) -> dict[str, Any]:
        return module.validate(self.root, HEAD, BUILD, PLATFORM_VERSION)

    def alter(self, app: str, key: str, value: object) -> None:
        path = self.paths[app][0] / "manifest.json"
        manifest = json.loads(path.read_text(encoding="utf-8"))
        manifest[key] = value
        path.write_text(json.dumps(manifest), encoding="utf-8")

    def test_complete_trio_proves_identical_source_without_claiming_trusted_signing(self) -> None:
        data = self.check()
        self.assertEqual(3, len(data["artifacts"]))
        self.assertEqual(HEAD, data["source_commit"])
        self.assertEqual(MOBILE_VERSION, data["mobile_version"])
        self.assertEqual(BUILD, data["build_number"])
        self.assertFalse(data["trusted_signing"])
        self.assertFalse(data["production_ready"])
        self.assertFalse(data["release_published"])

    def test_missing_app_is_rejected(self) -> None:
        directory = self.paths["van"][0]
        for child in directory.iterdir():
            child.unlink()
        directory.rmdir()
        with self.assertRaisesRegex(module.TrioEvidenceError, "Three and only three"):
            self.check()

    def test_cross_app_mixed_source_commit_is_rejected(self) -> None:
        self.alter("driver", "source_commit", "b" * 40)
        with self.assertRaisesRegex(module.TrioEvidenceError, "source_commit"):
            self.check()

    def test_corrupt_apk_byte_is_rejected(self) -> None:
        self.paths["customer"][1].write_bytes(b"synthetic-ci-apk-customer-tampered")
        with self.assertRaises(module.TrioEvidenceError):
            self.check()

    def test_symlink_apk_is_rejected_before_hashing(self) -> None:
        apk = self.paths["van"][1]
        external = tempfile.TemporaryDirectory()
        self.addCleanup(external.cleanup)
        outside = Path(external.name) / "outside.apk"
        outside.write_bytes(apk.read_bytes())
        apk.unlink()
        try:
            apk.symlink_to(outside)
        except (OSError, NotImplementedError):
            self.skipTest("Symlink unavailable on this runner.")
        with self.assertRaisesRegex(module.TrioEvidenceError, "Symbolic links"):
            self.check()

    def test_forged_trusted_signing_or_production_ready_is_rejected(self) -> None:
        self.alter("van", "signing", "trusted-staging-key")
        with self.assertRaisesRegex(module.TrioEvidenceError, "signing"):
            self.check()
        self.alter("van", "signing", "flutter-template-debug-key")
        self.alter("customer", "production_ready", True)
        with self.assertRaisesRegex(module.TrioEvidenceError, "production_ready"):
            self.check()

    def test_cross_app_version_or_build_mismatch_is_rejected(self) -> None:
        self.alter("driver", "mobile_version", "1.0.126")
        with self.assertRaisesRegex(module.TrioEvidenceError, "versions must match"):
            self.check()
        self.alter("driver", "mobile_version", MOBILE_VERSION)
        self.alter("driver", "build_number", "43")
        with self.assertRaisesRegex(module.TrioEvidenceError, "build_number"):
            self.check()

    def test_wrong_native_identity_and_checksums_are_rejected(self) -> None:
        self.alter("driver", "native_identity", "com.fiftysolution.foodex.customer")
        with self.assertRaisesRegex(module.TrioEvidenceError, "native_identity"):
            self.check()
        self.alter("driver", "native_identity", module.APPS["driver"])
        sums = self.paths["driver"][0] / "SHA256SUMS"
        sums.write_text("0" * 64 + "  fake.apk\n", encoding="utf-8")
        with self.assertRaisesRegex(module.TrioEvidenceError, "checksum"):
            self.check()

    def test_forged_purpose_or_extra_app_is_rejected(self) -> None:
        self.alter("customer", "purpose", "production-release")
        with self.assertRaisesRegex(module.TrioEvidenceError, "purpose"):
            self.check()
        self.alter("customer", "purpose", "ci-validation-only")
        (self.root / "v2-trio-unknown-extra").mkdir()
        with self.assertRaisesRegex(module.TrioEvidenceError, "Three and only three"):
            self.check()

    def test_empty_and_traversal_artifacts_are_rejected(self) -> None:
        self.paths["customer"][1].write_bytes(b"")
        with self.assertRaisesRegex(module.TrioEvidenceError, "empty"):
            self.check()
        self.paths["customer"][1].write_bytes(b"synthetic-ci-apk-customer")
        self.alter("van", "artifact", "../outside.apk")
        with self.assertRaisesRegex(module.TrioEvidenceError, "filename"):
            self.check()

    def test_linked_manifest_and_untrusted_source_sha_are_rejected(self) -> None:
        path = self.paths["customer"][0] / "manifest.json"
        external = tempfile.TemporaryDirectory()
        self.addCleanup(external.cleanup)
        outside = Path(external.name) / "outside.json"
        outside.write_bytes(path.read_bytes())
        path.unlink()
        try:
            path.symlink_to(outside)
        except (OSError, NotImplementedError):
            self.skipTest("Symlink unavailable on this runner.")
        with self.assertRaisesRegex(module.TrioEvidenceError, "Symbolic links"):
            self.check()
        with self.assertRaisesRegex(module.TrioEvidenceError, "Exact 40-character"):
            module.validate(self.root, "main", BUILD, PLATFORM_VERSION)


if __name__ == "__main__":
    unittest.main()
