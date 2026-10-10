from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "foodex-ui-intent-plan.py"
spec = importlib.util.spec_from_file_location("foodex_ui_intent_plan", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class FoodexUiIntentPlanTest(unittest.TestCase):
    def test_casebook_is_aligned_with_current_repo(self):
        self.assertEqual([], module.validate_casebook(module.ROOT))

    def test_arabic_van_collection_resolves(self):
        result = module.route_intent("اعمل صفحة تحصيل للفان من العميل")
        self.assertTrue(result["resolved"])
        self.assertEqual("van.collection", result["selected"]["id"])

    def test_arabic_van_remittance_resolves(self):
        result = module.route_intent("اعمل صفحة توريد للفان")
        self.assertTrue(result["resolved"])
        self.assertEqual("van.remittance", result["selected"]["id"])

    def test_dashboard_live_tracking_resolves(self):
        result = module.route_intent("عاوز شاشة تتبع السواقين والفانات في الداشبورد")
        self.assertTrue(result["resolved"])
        self.assertEqual("dashboard.live-tracking", result["selected"]["id"])

    def test_customer_wholesale_orders_resolves(self):
        result = module.route_intent("طلبات الجملة في تطبيق العميل")
        self.assertTrue(result["resolved"])
        self.assertEqual("customer.wholesale-orders", result["selected"]["id"])

    def test_driver_active_delivery_resolves(self):
        result = module.route_intent("مهام السائق والتسليم في تطبيق السائق")
        self.assertTrue(result["resolved"])
        self.assertEqual("driver.active-delivery", result["selected"]["id"])

    def test_generic_orders_does_not_force_wrong_journey(self):
        result = module.route_intent("اعمل صفحة طلبات")
        self.assertFalse(result["resolved"])

    def test_explicit_surface_constrains_routing(self):
        result = module.route_intent("مراجعة تحصيلات وتوريدات الفان", surface="dashboard")
        self.assertTrue(result["resolved"])
        self.assertEqual("dashboard.van-finance-support", result["selected"]["id"])

    def test_plan_inherits_recipe_quality_and_visual_contracts(self):
        result = module.route_intent("صفحة تحصيل للفان")
        plan = module.build_plan(result)
        self.assertEqual("invoice-collection", plan["business_recipe"]["id"])
        self.assertIn("interaction_tests", plan["quality_gates"])
        self.assertIn("viewports", plan["visual_evidence"])


if __name__ == "__main__":
    unittest.main()
