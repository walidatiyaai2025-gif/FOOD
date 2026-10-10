from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "scripts"
sys.path.insert(0, str(SCRIPTS))

import validate_b2b_van_contract_sync as contract  # noqa: E402


class B2BVanContractSyncTest(unittest.TestCase):
    def test_current_tree_is_synchronized(self) -> None:
        self.assertEqual([], contract.validate(ROOT))

    def test_missing_van_openapi_path_is_rejected(self) -> None:
        errors = contract.validate_openapi(
            "openapi: 3.1.0\n- name: Van Fulfillment\npaths:\n"
        )
        self.assertTrue(any("/van/orders:" in error for error in errors), errors)

    def test_visual_gate_requires_mobile_and_dashboard_evidence(self) -> None:
        errors = contract.validate_visual_workflow(
            "mobile_ui: ${{ steps.filter.outputs.mobile_ui }}\n"
            "dashboard_ui: ${{ steps.filter.outputs.dashboard_ui }}\n",
            {
                "areas": {
                    "mobile_ui": {
                        "path_regex": "apps/(customer_app|driver_app|van_app)/lib/.*\\.dart$"
                    },
                    "dashboard_ui": {
                        "path_regex": "backend/resources/views/admin/"
                    },
                }
            },
        )
        self.assertTrue(
            any("mobile-screenshot-capture.yml" in error for error in errors),
            errors,
        )
        self.assertTrue(any("ui-visual-qa.yml" in error for error in errors), errors)

    def test_release_lineage_requires_source_commit_validator(self) -> None:
        errors = contract.validate_release_lineage(
            "LATEST_RELEASE.json BUILD_INFO.json source commit",
            "version only",
            "python scripts/validate_release_artifact_contract.py",
        )
        self.assertTrue(any("source_commit" in error for error in errors), errors)


if __name__ == "__main__":
    unittest.main()
