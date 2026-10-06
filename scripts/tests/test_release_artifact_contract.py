from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

from validate_release_artifact_contract import ContractError, validate_release


VERSION = "1.0.58"
BUILD_NUMBER = 58
SOURCE_COMMIT = "a" * 40


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class ReleaseArtifactContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.release = Path(self.temp.name) / "Release"
        updates = self.release / "Updates"
        updates.mkdir(parents=True)

        build_info = {
            "schema_version": 1,
            "version": VERSION,
            "source_commit": SOURCE_COMMIT,
        }
        latest_apps = []

        for key, display in (
            ("customer", "Customer"),
            ("driver", "Driver"),
            ("van", "Van"),
        ):
            versioned = self.release / f"FOODEX-{display}-{VERSION}.apk"
            alias = self.release / f"FOODEX-{display}.apk"
            payload = f"{key}-apk".encode()
            versioned.write_bytes(payload)
            alias.write_bytes(payload)
            entry = {
                "file": versioned.name,
                "bytes": versioned.stat().st_size,
                "sha256": sha256(versioned),
                "version": VERSION,
                "build_number": BUILD_NUMBER,
            }
            build_info[key] = dict(entry)
            latest_apps.append(
                {
                    "app": key,
                    "version": VERSION,
                    "build_number": BUILD_NUMBER,
                    "file": versioned.name,
                    "latest_alias": alias.name,
                    "bytes": versioned.stat().st_size,
                    "sha256": sha256(versioned),
                }
            )

        setup = self.release / "FOODEX-Laravel-Setup.zip"
        setup.write_bytes(b"setup")
        build_info["laravel_setup"] = {
            "file": setup.name,
            "bytes": setup.stat().st_size,
            "sha256": sha256(setup),
        }

        (self.release / "BUILD_INFO.json").write_text(
            json.dumps(build_info), encoding="utf-8"
        )
        (self.release / "LATEST_RELEASE.json").write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "version": VERSION,
                    "build_number": BUILD_NUMBER,
                    "source_commit": SOURCE_COMMIT,
                    "dashboard_update": {
                        "directory": "Updates",
                        "package": "FOODEX-Update.zip",
                        "manifest": "FOODEX-Update.json",
                    },
                    "android_apps": latest_apps,
                }
            ),
            encoding="utf-8",
        )

        update = updates / "FOODEX-Update.zip"
        update.write_bytes(b"dashboard-update")
        digest = sha256(update)
        (updates / "FOODEX-Update.json").write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "package": update.name,
                    "target_version": VERSION,
                    "sha256": digest,
                    "requires_full_redeploy": False,
                }
            ),
            encoding="utf-8",
        )
        (updates / "FOODEX-Update.sha256.txt").write_text(
            f"{digest}  {update.name}\n", encoding="utf-8"
        )
        (updates / "FOODEX-Update.files.txt").write_text(
            "VERSION\nbackend/artisan\n", encoding="utf-8"
        )

    def tearDown(self) -> None:
        self.temp.cleanup()

    def validate(self) -> None:
        validate_release(
            self.release,
            version=VERSION,
            build_number=BUILD_NUMBER,
            source_commit=SOURCE_COMMIT,
        )

    def test_complete_release_passes(self) -> None:
        self.validate()

    def test_missing_van_artifact_fails(self) -> None:
        (self.release / f"FOODEX-Van-{VERSION}.apk").unlink()
        with self.assertRaisesRegex(ContractError, "FOODEX-Van"):
            self.validate()

    def test_latest_manifest_must_list_exactly_three_apps(self) -> None:
        path = self.release / "LATEST_RELEASE.json"
        payload = json.loads(path.read_text(encoding="utf-8"))
        payload["android_apps"] = [
            item for item in payload["android_apps"] if item["app"] != "van"
        ]
        path.write_text(json.dumps(payload), encoding="utf-8")
        with self.assertRaisesRegex(ContractError, "app set"):
            self.validate()

    def test_anonymous_app_release_is_rejected(self) -> None:
        (self.release / "app-release.apk").write_bytes(b"anonymous")
        with self.assertRaisesRegex(ContractError, "Anonymous app-release.apk"):
            self.validate()

    def test_full_redeploy_allows_manifest_without_update_zip(self) -> None:
        updates = self.release / "Updates"
        manifest_path = updates / "FOODEX-Update.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest["requires_full_redeploy"] = True
        manifest["sha256"] = None
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        (updates / "FOODEX-Update.zip").unlink()
        (updates / "FOODEX-Update.sha256.txt").unlink()
        (updates / "FOODEX-Update.files.txt").unlink()
        self.validate()

    def test_trial_distribution_wires_contract_before_publication(self) -> None:
        workflow = (ROOT / ".github/workflows/trial-distribution.yml").read_text(
            encoding="utf-8"
        )
        validator = "python3 scripts/validate_release_artifact_contract.py"
        immutable_guard = "- name: Guard immutable published release"
        self.assertIn(validator, workflow)
        self.assertIn(immutable_guard, workflow)
        self.assertLess(workflow.index(validator), workflow.index(immutable_guard))
        for token in (
            "FOODEX-Customer-${CURRENT_VERSION}.apk",
            "FOODEX-Driver-${CURRENT_VERSION}.apk",
            "FOODEX-Van-${CURRENT_VERSION}.apk",
            "Release/LATEST_RELEASE.json",
            "release/221-generated-trial-bundle",
        ):
            self.assertIn(token, workflow)


if __name__ == "__main__":
    unittest.main()
