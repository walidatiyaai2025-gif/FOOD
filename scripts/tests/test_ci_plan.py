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
    def test_release_contract_without_mobile_paths_remains_dashboard_only_for_platform_builds(self):
        p=module.resolve(
            self.config,
            ["backend/app/Services/AssistantExternalAiClient.php","docs/release/UPDATE_NOTES_1.0.107.md"],
            "fix/2397-foodex-107-ai-provenance",
        )
        self.assertTrue(p["backend_dashboard_only"])
        for area in ("customer","driver","van"):
            self.assertTrue(p["areas"][area],area)

    def test_customer_freeze_validator_expands_all_runtime_areas(self):
        p=module.resolve(self.config,["scripts/validate_customer_freeze_contract.py"],"worker/1381-customer-freeze-release-contract")
        for a in ("backend","customer","driver","van"): self.assertTrue(p["areas"][a])
    def test_branch_override_is_machine_readable(self):
        p=module.resolve(self.config,[],"test/1253-operational-completion-e2e")
        self.assertTrue(p["areas"]["operational_completion_e2e"]); self.assertTrue(p["areas"]["app_preview_visual"]); self.assertTrue(p["areas"]["van"]); self.assertTrue(p["areas"]["dashboard_ui"])
    def test_customer_order_change_resolves_high_signal_tests(self):
        p=module.resolve(self.config,["apps/customer_app/lib/features/orders/orders_screen.dart"],"feat/1253-test")
        self.assertIn("test/customer_orders_ui_v3_test.dart",p["focused_tests"]["customer"])
        self.assertIn("test/retail_commerce_test.dart",p["focused_tests"]["customer"])
    def test_backend_van_change_resolves_previous_failure_tests(self):
        p=module.resolve(self.config,["backend/app/Services/VanDeliveryExecutionService.php"],"feat/1253-test")
        self.assertIn("tests/Feature/MobileSystemInspectorEventTest.php",p["focused_tests"]["backend"])
        self.assertIn("tests/Feature/VanDeliveryEvidenceDashboardTest.php",p["focused_tests"]["backend"])
    def test_backend_dashboard_only_scope_never_enables_mobile_lanes(self):
        p=module.resolve(self.config,["backend/app/Services/OrderService.php","backend/resources/views/admin/orders/index.blade.php"],"feat/1738-ci-scope")
        self.assertTrue(p["backend_dashboard_only"])
        for area in ("customer","driver","van","mobile_ui"):
            self.assertFalse(p["areas"][area],area)
    def test_dashboard_visual_gate_changes_trigger_dashboard_runtime_evidence(self):
        for path in (
            "scripts/capture_web_screenshots.mjs",
            "scripts/foodex-ui-pr-plan.py",
            ".ai/uiux/visual-evidence-matrix.json",
            ".github/workflows/ui-visual-qa.yml",
        ):
            p=module.resolve(self.config,[path],"test/1943-responsive-runtime-gate")
            self.assertTrue(p["areas"]["dashboard_ui"],path)
            self.assertFalse(p["areas"]["mobile_ui"],path)

    def test_final_convergence_branch_can_explicitly_restore_mobile_lanes(self):
        p=module.resolve(self.config,["backend/app/Services/OrderService.php"],"test/1253-operational-completion-e2e")
        self.assertFalse(p["backend_dashboard_only"])
        for area in ("customer","driver","van"):
            self.assertTrue(p["areas"][area],area)
if __name__=="__main__": unittest.main()
