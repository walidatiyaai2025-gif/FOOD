from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "foodex-skill-router.py"
spec = importlib.util.spec_from_file_location("foodex_skill_router", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class FoodexSkillRouterTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.config = module.load_router(module.ROOT)

    def resolve(self, path: str, added_text: str = "") -> dict:
        return module.resolve(
            self.config,
            [module.ChangedFile(status="M", path=path, added_text=added_text)],
        )

    def test_repository_router_config_is_valid(self):
        self.assertEqual([], module.validate_router(self.config, module.ROOT))

    def test_customer_change_selects_focused_surface_pack(self):
        report = self.resolve("apps/customer_app/lib/features/orders/orders_screen.dart")
        self.assertIn("customer-ui", report["routes"])
        self.assertIn("customer-uiux.md", report["skills"])
        self.assertIn("ui-route-authority.md", report["skills"])
        self.assertNotIn("dashboard-uiux.md", report["skills"])
        self.assertNotIn("driver-uiux.md", report["skills"])
        self.assertNotIn("ui-interaction-safety.md", report["skills"])
        self.assertNotIn("ui-performance.md", report["skills"])

    def test_mutating_ui_adds_interaction_safety_only_when_detected(self):
        report = self.resolve(
            "apps/van_app/lib/features/delivery/delivery_screen.dart",
            "await api.post('/orders/1/dispatch');",
        )
        self.assertIn("state-changing-ui", report["conditional_rules"])
        self.assertIn("ui-interaction-safety.md", report["skills"])
        self.assertIn("mutation-safety", report["gates"])

    def test_list_search_live_ui_adds_performance_skill(self):
        report = self.resolve(
            "apps/driver_app/lib/features/orders/orders_screen.dart",
            "return ListView.builder(itemBuilder: buildRow); // search",
        )
        self.assertIn("high-growth-live-ui", report["conditional_rules"])
        self.assertIn("ui-performance.md", report["skills"])
        self.assertIn("performance", report["gates"])

    def test_dashboard_table_selects_dashboard_and_performance(self):
        report = self.resolve(
            "backend/resources/views/admin/orders/index.blade.php",
            "<table data-pagination-required=\"true\"></table>",
        )
        self.assertIn("dashboard-ui", report["routes"])
        self.assertIn("dashboard-uiux.md", report["skills"])
        self.assertIn("ui-performance.md", report["skills"])
        self.assertNotIn("customer-uiux.md", report["skills"])

    def test_database_change_does_not_load_ui_pack(self):
        report = self.resolve(
            "backend/database/migrations/2026_10_10_000000_add_state.php"
        )
        self.assertEqual(["database"], list(report["routes"]))
        self.assertEqual(["database-change.md"], report["skills"])
        self.assertNotIn("uiux-policy", report["gates"])

    def test_release_workflow_can_select_release_and_ci_without_ui(self):
        report = self.resolve(".github/workflows/release-validation.yml")
        self.assertIn("release", report["routes"])
        self.assertIn("ci", report["routes"])
        self.assertIn("release.md", report["skills"])
        self.assertIn("ci-repair.md", report["skills"])
        self.assertNotIn("foodex-uiux.md", report["skills"])

    def test_controlled_surface_can_never_silently_bypass_router(self):
        config = dict(self.config)
        config["routes"] = [
            route for route in self.config["routes"] if route["id"] != "customer-ui"
        ]
        report = module.resolve(
            config,
            [module.ChangedFile(
                status="M",
                path="apps/customer_app/lib/features/x.dart",
                added_text="",
            )],
        )
        self.assertFalse(report["ok"])
        self.assertEqual(
            ["apps/customer_app/lib/features/x.dart"],
            report["unmatched_controlled_paths"],
        )


if __name__ == "__main__":
    unittest.main()
