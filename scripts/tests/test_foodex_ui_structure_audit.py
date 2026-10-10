from __future__ import annotations

import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "foodex-ui-structure-audit.py"
spec = importlib.util.spec_from_file_location("foodex_ui_structure_audit", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class FoodexUiStructureAuditTest(unittest.TestCase):
    def test_required_groups_detect_missing_any_and_all(self):
        groups = [
            {"id": "shell", "all": ["a", "b"]},
            {"id": "header", "any": ["c", "d"]},
        ]
        self.assertEqual([], module.group_errors("a b d", groups))
        self.assertEqual(["shell", "header"], module.group_errors("a", groups))

    def test_jaccard_similarity(self):
        self.assertEqual(1.0, module.jaccard({"a", "b"}, {"a", "b"}))
        self.assertAlmostEqual(1 / 3, module.jaccard({"a", "b"}, {"b", "c"}))

    def test_drift_marker_is_hard_error(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            target = root / "canonical.txt"
            target.write_text("alpha", encoding="utf-8")
            config = {"drift_sources": {"dashboard": {"canonical.txt": ["alpha", "beta"]}}}
            findings = module.drift_findings(config, root)
            self.assertEqual(1, len(findings))
            self.assertEqual("error", findings[0].severity)
            self.assertIn("beta", findings[0].message)

    def test_surface_detection_uses_config_regex(self):
        config = {"surfaces": {"customer": {"screen_regex": r"^apps/customer_app/lib/.*_screen\.dart$"}}}
        self.assertEqual("customer", module.surface_for("apps/customer_app/lib/foo_screen.dart", config))
        self.assertIsNone(module.surface_for("apps/customer_app/lib/foo_model.dart", config))


if __name__ == "__main__":
    unittest.main()
