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

    def test_customer_change_selects_ui_and_flutter_authoring_packs(self):
        report = self.resolve("apps/customer_app/lib/features/orders/orders_screen.dart")
        self.assertIn("customer-ui", report["routes"])
        self.assertIn("flutter-core", report["routes"])
        self.assertIn("customer-uiux.md", report["skills"])
        self.assertIn("flutter-authoring.md", report["skills"])
        self.assertIn("flutter-state-safety.md", report["skills"])
        self.assertIn("flutter-test-authoring.md", report["skills"])
        self.assertIn("flutter-analysis", report["gates"])
        self.assertNotIn("dashboard-uiux.md", report["skills"])
        self.assertNotIn("driver-uiux.md", report["skills"])

    def test_flutter_test_file_gets_authoring_without_ui_pack(self):
        report = self.resolve("apps/driver_app/test/order_state_test.dart")
        self.assertEqual(["flutter-core"], list(report["routes"]))
        self.assertIn("flutter-authoring.md", report["skills"])
        self.assertIn("flutter-test-authoring.md", report["skills"])
        self.assertNotIn("driver-uiux.md", report["skills"])
        self.assertTrue(report["ok"])

    def test_async_change_adds_lifecycle_skill(self):
        report = self.resolve(
            "apps/customer_app/lib/features/orders/order_screen.dart",
            "final response = await repository.load();\nsetState(() {});",
        )
        self.assertIn("flutter-async-lifecycle", report["conditional_rules"])
        self.assertIn("flutter-async-lifecycle.md", report["skills"])
        self.assertIn("flutter-lifecycle-safety", report["gates"])

    def test_api_change_adds_api_model_skill(self):
        report = self.resolve(
            "apps/van_app/lib/data/orders_repository.dart",
            "final uri = Uri.parse(path);\nfinal data = jsonDecode(response.body);",
        )
        self.assertIn("flutter-api-model", report["conditional_rules"])
        self.assertIn("flutter-api-model-contract.md", report["skills"])
        self.assertIn("flutter-api-contract", report["gates"])

    def test_navigation_change_adds_navigation_skill(self):
        report = self.resolve(
            "apps/driver_app/lib/features/orders/order_screen.dart",
            "await Navigator.of(context).push(route);",
        )
        self.assertIn("flutter-navigation", report["conditional_rules"])
        self.assertIn("flutter-navigation-safety.md", report["skills"])

    def test_user_visible_text_adds_localization_skill(self):
        report = self.resolve(
            "apps/customer_app/lib/features/orders/order_screen.dart",
            "return Text(label);",
        )
        self.assertIn("flutter-localization", report["conditional_rules"])
        self.assertIn("flutter-localization-code.md", report["skills"])
        self.assertIn("localization", report["gates"])

    def test_mutating_ui_adds_interaction_safety_only_when_detected(self):
        report = self.resolve(
            "apps/van_app/lib/features/delivery/delivery_screen.dart",
            "await api.post('/orders/1/dispatch');",
        )
        self.assertIn("state-changing-ui", report["conditional_rules"])
        self.assertIn("ui-interaction-safety.md", report["skills"])
        self.assertIn("mutation-safety", report["gates"])

    def test_flutter_list_search_uses_flutter_performance_skill(self):
        report = self.resolve(
            "apps/driver_app/lib/features/orders/orders_screen.dart",
            "return ListView.builder(itemBuilder: buildRow); // search",
        )
        self.assertIn("flutter-performance", report["conditional_rules"])
        self.assertIn("flutter-performance-code.md", report["skills"])
        self.assertIn("flutter-performance", report["gates"])
        self.assertNotIn("ui-performance.md", report["skills"])

    def test_dashboard_table_selects_dashboard_ui_performance(self):
        report = self.resolve(
            "backend/resources/views/admin/orders/index.blade.php",
            '<table data-pagination-required="true"></table>',
        )
        self.assertIn("dashboard-ui", report["routes"])
        self.assertIn("dashboard-uiux.md", report["skills"])
        self.assertIn("ui-performance.md", report["skills"])
        self.assertNotIn("flutter-performance-code.md", report["skills"])

    def test_database_change_does_not_load_ui_or_flutter_pack(self):
        report = self.resolve(
            "backend/database/migrations/2026_10_10_000000_add_state.php"
        )
        self.assertEqual(["database"], list(report["routes"]))
        self.assertEqual(["database-change.md"], report["skills"])
        self.assertNotIn("uiux-policy", report["gates"])
        self.assertNotIn("flutter-authoring", report["gates"])

    def test_release_workflow_can_select_release_and_ci_without_ui(self):
        report = self.resolve(".github/workflows/release-validation.yml")
        self.assertIn("release", report["routes"])
        self.assertIn("ci", report["routes"])
        self.assertIn("release.md", report["skills"])
        self.assertIn("ci-repair.md", report["skills"])
        self.assertNotIn("foodex-uiux.md", report["skills"])
        self.assertNotIn("flutter-authoring.md", report["skills"])

    def test_controlled_flutter_test_can_never_silently_bypass_router(self):
        config = dict(self.config)
        config["routes"] = [
            route for route in self.config["routes"] if route["id"] != "flutter-core"
        ]
        report = module.resolve(
            config,
            [module.ChangedFile(
                status="M",
                path="apps/customer_app/test/x_test.dart",
                added_text="",
            )],
        )
        self.assertFalse(report["ok"])
        self.assertEqual(
            ["apps/customer_app/test/x_test.dart"],
            report["unmatched_controlled_paths"],
        )

    def test_controlled_customer_surface_can_never_silently_bypass_router(self):
        config = dict(self.config)
        config["routes"] = [
            route for route in self.config["routes"] if route["id"] not in {"customer-ui", "flutter-core"}
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
