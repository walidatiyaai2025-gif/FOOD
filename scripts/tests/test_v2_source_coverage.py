"""V2 G002 structural source traceability negative regression tests."""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SCRIPTS))
import audit_v2_source_coverage as source_audit  # noqa: E402


class V2SourceCoverageTests(unittest.TestCase):
    def test_repository_coverage_is_complete_without_source_implementation_claim(self):
        report = source_audit.audit()
        self.assertTrue(report["ok"], report["errors"])
        self.assertFalse(report["source_verified"])
        self.assertEqual(report["status"], "MATRIX_REVIEW_ONLY")
        self.assertEqual(report["matrix_issues"], 422)
        self.assertEqual(report["source_paragraphs_mapped"], 300)
        self.assertEqual(report["source_paragraphs_contextual"], 74)
        self.assertEqual(report["table_data_rows_mapped"], 101)
        self.assertEqual(report["table_data_rows_contextual"], 11)
        self.assertEqual(report["derived_mobile_and_gates"], 21)

    def test_fail_closed_on_unreviewed_source_line(self):
        self.assertTrue(any("unreviewed" in x for x in source_audit.verify_partition("P", {0, 1, 2}, {1}, {0})))

    def test_fail_closed_on_source_line_both_contextual_and_actionable(self):
        self.assertTrue(any("both" in x for x in source_audit.verify_partition("P", {0, 1}, {0}, {0, 1})))

    def test_fail_closed_on_nonexistent_native_row(self):
        self.assertTrue(any("out-of-bounds" in x for x in source_audit.verify_partition("table", {(0, 1)}, {(0, 2)}, {(0, 1)})))

    def test_ledger_rejects_silent_review_omission(self):
        original = json.loads((source_audit.ROOT / source_audit.LEDGER).read_text(encoding="utf-8"))
        original["contextual_paragraphs"]["section_headings"].remove(4)
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            output = root / source_audit.LEDGER
            output.parent.mkdir(parents=True)
            output.write_text(json.dumps(original), encoding="utf-8")
            for p in source_audit.MATRICES:
                target = root / p
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes((source_audit.ROOT / p).read_bytes())
            with patch.object(source_audit, "validate_contract", return_value=[]):
                result = source_audit.audit(root=root)
            self.assertFalse(result["ok"])
            self.assertTrue(any("unreviewed" in x for x in result["errors"]))

    def test_wrong_source_is_not_claimed_verified(self):
        with tempfile.TemporaryDirectory() as tmp:
            invalid = Path(tmp) / "wrong.docx"
            invalid.write_bytes(b"not a valid Word archive")
            report = source_audit.audit(source=invalid)
        self.assertFalse(report["ok"])
        self.assertFalse(report["source_verified"])


if __name__ == "__main__":
    unittest.main()
