from __future__ import annotations

import importlib.util
import json
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "validate_v2_unified_contract",
    ROOT / "scripts/validate_v2_unified_contract.py",
)
assert SPEC and SPEC.loader
GATE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = GATE
SPEC.loader.exec_module(GATE)


class UnifiedV2GovernanceContractTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        paths = list(GATE.MATRICES) + [
            "docs/v2/V2_UNIFIED_GOVERNANCE_CONTRACT.json"
        ]
        for path in paths:
            target = self.root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / path, target)

    def mutate(self, path: str, change):
        file = self.root / path
        content = json.loads(file.read_text(encoding="utf-8"))
        change(content)
        file.write_text(json.dumps(content, ensure_ascii=False), encoding="utf-8")

    def assert_rejected(self, fragment: str):
        self.assertTrue(
            any(fragment in error for error in GATE.validate(self.root)),
            GATE.validate(self.root),
        )

    def test_unmodified_four_matrices_pass(self):
        self.assertEqual([], GATE.validate(self.root))

    def test_missing_source_row_fails_closed(self):
        path = "docs/v2/V2_FUNCTIONAL_ISSUE_MATRIX.json"
        self.mutate(path, lambda data: data["items"].pop())
        self.assert_rejected("source-linked rows")

    def test_reused_issue_number_fails_closed(self):
        path = "docs/v2/V2_ACCOUNT_EXPORT_AUDIT_MATRIX.json"
        self.mutate(path, lambda data: data["items"][0].update(issue_number=2905))
        self.assert_rejected("duplicate issue #2905")

    def test_wrong_branch_fails_closed(self):
        path = "docs/v2/V2_FUNCTIONAL_ISSUE_MATRIX.json"
        self.mutate(path, lambda data: data.update(base="main"))
        self.assert_rejected("invalid base")

    def test_missing_native_pointer_fails_closed(self):
        path = "docs/v2/V2_SIDEBAR_ISSUE_MATRIX.json"
        self.mutate(path, lambda data: data["items"][0].pop("document_paragraph_id"))
        self.assert_rejected("native DOCX source pointer")

    def test_2905_anchor_cannot_drift(self):
        path = "docs/v2/V2_FUNCTIONAL_ISSUE_MATRIX.json"
        self.mutate(
            path,
            lambda data: next(
                row for row in data["items"] if row["issue_number"] == 2905
            ).update(document_source_pointer="Native DOCX paragraph P385"),
        )
        self.assert_rejected("incorrect owner Issue #2905")

    def test_missing_permission_dimension_rejected(self):
        path = "docs/v2/V2_UNIFIED_GOVERNANCE_CONTRACT.json"
        self.mutate(path, lambda data: data["dimensions"].pop("RBAC"))
        self.assert_rejected("all four UX/Backend/RBAC/ERP")

    def test_missing_mobile_app_review_rejected(self):
        path = "docs/v2/V2_UNIFIED_GOVERNANCE_CONTRACT.json"
        self.mutate(path, lambda data: data["acceptance"].update(mobile_apps=["Customer", "Driver"]))
        self.assert_rejected("Customer, Driver, Van")

    def test_removed_backend_403_invariant_rejected(self):
        path = "docs/v2/V2_UNIFIED_GOVERNANCE_CONTRACT.json"
        self.mutate(path, lambda data: data["acceptance"].update(backend_denial_status=200))
        self.assert_rejected("direct-route deny")

    def test_disabled_module_not_ready_regression_rejected(self):
        path = "docs/v2/V2_UNIFIED_GOVERNANCE_CONTRACT.json"
        self.mutate(path, lambda data: data["invariants"].update(no_enabled_implies_erp_ready=False))
        self.assert_rejected("invariants incomplete")


if __name__ == "__main__":
    unittest.main()
