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

    def test_dashboard_page_local_plan_requires_desktop_tablet_mobile_and_320(self):
        matrix = {
            "surfaces": {
                "dashboard": {
                    "directions": ["ar-rtl", "en-ltr"],
                    "representative_viewports": [
                        "desktop-1280", "compact-1024", "tablet-768", "mobile-390", "mobile-320"
                    ],
                    "shared_primitive_viewports": [
                        "desktop-1280", "compact-1024", "tablet-768", "mobile-430",
                        "mobile-412", "mobile-390", "mobile-360", "mobile-320"
                    ],
                }
            }
        }
        impact = module.responsive_impact(
            "dashboard", [("M", "backend/resources/views/admin/orders.blade.php")], matrix
        )
        self.assertTrue(impact["desktop"] and impact["tablet"] and impact["mobile"])
        self.assertEqual("page-local-representative", impact["coverage_profile"])
        self.assertIn("mobile-320", impact["required_viewports"])
        self.assertEqual(["ar-rtl", "en-ltr"], impact["directions"])

    def test_dashboard_shared_primitive_uses_full_width_matrix(self):
        matrix = {
            "surfaces": {
                "dashboard": {
                    "directions": ["ar-rtl", "en-ltr"],
                    "representative_viewports": ["desktop-1280", "tablet-768", "mobile-390", "mobile-320"],
                    "shared_primitive_viewports": [
                        "desktop-1280", "compact-1024", "tablet-768", "mobile-430",
                        "mobile-412", "mobile-390", "mobile-360", "mobile-320"
                    ],
                }
            }
        }
        impact = module.responsive_impact(
            "dashboard", [("M", "backend/public/assets/admin/admin.css")], matrix
        )
        self.assertEqual("shared-primitive-full-matrix", impact["coverage_profile"])
        self.assertEqual(8, len(impact["required_viewports"]))
        for width in ("mobile-320", "mobile-360", "mobile-390", "mobile-412", "mobile-430"):
            self.assertIn(width, impact["required_viewports"])


if __name__ == "__main__":
    unittest.main()
