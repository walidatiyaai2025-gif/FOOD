from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "foodex-ui-pr-plan.py"
spec = importlib.util.spec_from_file_location("foodex_ui_pr_plan", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class FoodexUiPrPlanTest(unittest.TestCase):
    def test_new_customer_screen_requires_evidence_inventory_change(self):
        signatures = {
            "surfaces": {
                "customer": {"screen_regex": r"^apps/customer_app/lib/.*_screen\.dart$"}
            }
        }
        matrix = {
            "surfaces": {
                "customer": {"evidence_inventory": "apps/customer_app/test/screenshot_evidence_test.dart"}
            }
        }
        rows = [("A", "apps/customer_app/lib/new_screen.dart")]
        errors = module.inventory_errors(rows, signatures, matrix)
        self.assertEqual(1, len(errors))
        self.assertIn("screenshot_evidence_test.dart", errors[0])

    def test_new_customer_screen_passes_when_inventory_changes(self):
        signatures = {
            "surfaces": {
                "customer": {"screen_regex": r"^apps/customer_app/lib/.*_screen\.dart$"}
            }
        }
        matrix = {
            "surfaces": {
                "customer": {"evidence_inventory": "apps/customer_app/test/screenshot_evidence_test.dart"}
            }
        }
        rows = [
            ("A", "apps/customer_app/lib/new_screen.dart"),
            ("M", "apps/customer_app/test/screenshot_evidence_test.dart"),
        ]
        self.assertEqual([], module.inventory_errors(rows, signatures, matrix))

    def test_ui_surface_detection(self):
        self.assertEqual("dashboard", module.ui_surface("backend/resources/views/admin/orders.blade.php"))
        self.assertEqual("van", module.ui_surface("apps/van_app/lib/features/orders/van_orders_page.dart"))
        self.assertEqual("shared-mobile", module.ui_surface("packages/design_tokens/lib/tokens.dart"))
        self.assertIsNone(module.ui_surface("README.md"))


if __name__ == "__main__":
    unittest.main()
