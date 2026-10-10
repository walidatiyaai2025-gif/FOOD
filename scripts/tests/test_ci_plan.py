from __future__ import annotations
import importlib.util, sys, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("foodex_ci_plan",ROOT/"scripts/ci-plan.py")
module=importlib.util.module_from_spec(spec); assert spec and spec.loader
sys.modules[spec.name]=module; spec.loader.exec_module(module)

class CiPlanTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls): cls.config=module.load_config()
    def test_config_is_valid(self): self.assertEqual([],module.validate_config(self.config))
    def test_van_change_is_first_class_area(self):
        p=module.resolve(self.config,["apps/van_app/lib/features/orders/van_order_screen.dart"],"feat/1253-test")
        self.assertTrue(p["areas"]["van"]); self.assertTrue(p["areas"]["mobile_ui"]); self.assertFalse(p["areas"]["customer"])
    def test_release_contract_expands_all_runtime_areas(self):
        p=module.resolve(self.config,["docs/release/RELEASE_ARTIFACT_CONTRACT.md"],"docs/1253-release")
        for a in ("backend","customer","driver","van"): self.assertTrue(p["areas"][a])
    def test_branch_override_is_machine_readable(self):
        p=module.resolve(self.config,[],"test/1253-operational-completion-e2e")
        self.assertTrue(p["areas"]["operational_completion_e2e"]); self.assertTrue(p["areas"]["app_preview_visual"]); self.assertTrue(p["areas"]["van"])
    def test_customer_order_change_resolves_high_signal_tests(self):
        p=module.resolve(self.config,["apps/customer_app/lib/features/orders/orders_screen.dart"],"feat/1253-test")
        self.assertIn("test/customer_orders_ui_v3_test.dart",p["focused_tests"]["customer"])
        self.assertIn("test/retail_commerce_test.dart",p["focused_tests"]["customer"])
    def test_backend_van_change_resolves_previous_failure_tests(self):
        p=module.resolve(self.config,["backend/app/Services/VanDeliveryExecutionService.php"],"feat/1253-test")
        self.assertIn("tests/Feature/MobileSystemInspectorEventTest.php",p["focused_tests"]["backend"])
        self.assertIn("tests/Feature/VanDeliveryEvidenceDashboardTest.php",p["focused_tests"]["backend"])
if __name__=="__main__": unittest.main()
