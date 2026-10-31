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

    def configure_frozen_customer(self) -> Path:
        frozen_version = "1.0.57"
        frozen_build = 57
        baseline = "b" * 40
        tree_sha = "c" * 40
        old = self.release / f"FOODEX-Customer-{VERSION}.apk"
        frozen = self.release / f"FOODEX-Customer-{frozen_version}.apk"
        old.rename(frozen)

        build_path = self.release / "BUILD_INFO.json"
        build_info = json.loads(build_path.read_text(encoding="utf-8"))
        build_info["customer"].update(
            {
                "file": frozen.name,
                "version": frozen_version,
                "build_number": frozen_build,
                "release_version": VERSION,
                "lineage_mode": "carried_forward",
                "source_commit": baseline,
                "tree_sha": tree_sha,
            }
        )
        build_path.write_text(json.dumps(build_info), encoding="utf-8")

        latest_path = self.release / "LATEST_RELEASE.json"
        latest = json.loads(latest_path.read_text(encoding="utf-8"))
        customer = next(item for item in latest["android_apps"] if item["app"] == "customer")
        customer.update(
            {
                "file": frozen.name,
                "version": frozen_version,
                "build_number": frozen_build,
                "release_version": VERSION,
                "lineage_mode": "carried_forward",
                "source_commit": baseline,
                "tree_sha": tree_sha,
            }
        )
        latest_path.write_text(json.dumps(latest), encoding="utf-8")

        contract = Path(self.temp.name) / "customer-freeze.json"
        contract.write_text(
            json.dumps(
                {
                    "release_version": VERSION,
                    "frozen_customer": {
                        "source_version": frozen_version,
                        "source_build_number": frozen_build,
                        "baseline_commit": baseline,
                        "tree_sha": tree_sha,
                        "lineage_mode": "carried_forward",
                    },
                }
            ),
            encoding="utf-8",
        )
        return contract

    def test_complete_release_passes(self) -> None:
        self.validate()

    def test_frozen_customer_may_be_truthfully_carried_into_newer_platform_release(self) -> None:
        contract = self.configure_frozen_customer()
        validate_release(
            self.release,
            version=VERSION,
            build_number=BUILD_NUMBER,
            source_commit=SOURCE_COMMIT,
            customer_freeze_contract=contract,
        )

    def test_frozen_customer_cannot_be_claimed_as_platform_version(self) -> None:
        contract = self.configure_frozen_customer()
        latest_path = self.release / "LATEST_RELEASE.json"
        latest = json.loads(latest_path.read_text(encoding="utf-8"))
        customer = next(item for item in latest["android_apps"] if item["app"] == "customer")
        customer["version"] = VERSION
        latest_path.write_text(json.dumps(latest), encoding="utf-8")
        with self.assertRaisesRegex(ContractError, "customer version"):
            validate_release(
                self.release,
                version=VERSION,
                build_number=BUILD_NUMBER,
                source_commit=SOURCE_COMMIT,
                customer_freeze_contract=contract,
            )

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

    def test_trial_distribution_validates_tester_artifacts_without_publication(self) -> None:
        workflow = (ROOT / ".github/workflows/trial-distribution.yml").read_text(
            encoding="utf-8"
        )
        validator = "python3 scripts/validate_release_artifact_contract.py"
        upload = "- name: Upload distribution artifact"
        self.assertIn(validator, workflow)
        self.assertIn(upload, workflow)
        self.assertLess(workflow.index(validator), workflow.index(upload))
        self.assertIn("permissions:\n  contents: read", workflow)
        for token in (
            "FOODEX-Customer-${CURRENT_VERSION}.apk",
            "FOODEX-Driver-${CURRENT_VERSION}.apk",
            "FOODEX-Van-${CURRENT_VERSION}.apk",
            "Release/LATEST_RELEASE.json",
        ):
            self.assertIn(token, workflow)
        # The release/* workflow must never mutate canonical immutable Releases,
        # tags or the generated distribution branch before a protected-main merge.
        for forbidden in (
            "gh release",
            "git push",
            "release/221-generated-trial-bundle",
            "contents: write",
        ):
            self.assertNotIn(forbidden, workflow)


if __name__ == "__main__":
    unittest.main()
