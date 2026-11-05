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

    def test_screenshot_workflows_are_retired(self) -> None:
        self.assertEqual(
            [], contract.validate_no_screenshot_workflow("name: required-ci-gate\n", {})
        )
        errors = contract.validate_no_screenshot_workflow(
            "name: required-ci-gate\n"
            "uses: ./.github/workflows/ui-visual-qa.yml\n"
            "uses: ./.github/workflows/mobile-screenshot-capture.yml\n", {}
        )
        self.assertEqual(2, len(errors))

    def test_release_lineage_requires_source_commit_validator(self) -> None:
        errors = contract.validate_release_lineage(
            "LATEST_RELEASE.json BUILD_INFO.json source commit",
            "version only",
            "python scripts/validate_release_artifact_contract.py",
        )
        self.assertTrue(any("source_commit" in error for error in errors), errors)


if __name__ == "__main__":
    unittest.main()
