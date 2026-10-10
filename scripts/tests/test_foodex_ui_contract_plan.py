from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "foodex-ui-contract-plan.py"
spec = importlib.util.spec_from_file_location("foodex_ui_contract_plan", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class FoodexUiContractPlanTest(unittest.TestCase):
    def test_customer_order_plan_resolves_canonical_authority(self):
        plan = module.build_plan(
            "customer",
            "customer-transaction",
            function_id="customer.wholesale.orders",
        )
        self.assertEqual("customer", plan["surface"])
        self.assertEqual("/b2b/orders,/b2b/orders/:id", plan["route"])
        self.assertEqual("WholesaleOrdersDesignScreen / WholesaleOrderDetailsDesignScreen", plan["function"]["authority"])
        self.assertIn("apps/customer_app/lib/core/routing/customer_route_authority.dart", plan["route_authority"].values())
        self.assertTrue(plan["quality_gates"]["required_tests"])
        self.assertIn("currency_source_and_precision", plan["context_required_inputs"])

    def test_dashboard_management_plan_includes_context_and_golden_pages(self):
        plan = module.build_plan("dashboard", "management-list", route="/admin/example")
        self.assertEqual("/admin/example", plan["route"])
        self.assertTrue(plan["golden_page_candidates"])
        self.assertIn("tenant_context_source", plan["authority_documents"])
        self.assertIn("context_tests", plan["quality_gates"])

    def test_invalid_surface_or_archetype_fails(self):
        with self.assertRaises(ValueError):
            module.build_plan("unknown", "management-list")
        with self.assertRaises(ValueError):
            module.build_plan("customer", "management-list")


if __name__ == "__main__":
    unittest.main()
