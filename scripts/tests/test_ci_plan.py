from __future__ import annotations
import importlib.util, subprocess, sys, tempfile, unittest
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
        ):
            p=module.resolve(self.config,[path],"test/1943-responsive-runtime-gate")
            self.assertTrue(p["areas"]["dashboard_ui"],path)
            self.assertFalse(p["areas"]["mobile_ui"],path)
        workflow=module.resolve(self.config,[".github/workflows/ui-visual-qa.yml"],"test/1943-responsive-runtime-gate")
        self.assertTrue(workflow["areas"]["dashboard_ui"])
        self.assertTrue(workflow["areas"]["mobile_ui"])
        self.assertFalse(workflow["backend_dashboard_only"])

    def test_v2_g003_final_matrix_includes_all_three_mobile_apps_and_backend(self):
        """Final isolated V2 head must not silently skip Flutter for a CI-only diff."""
        p=module.resolve(
            self.config,
            [".ci/ci-map.json", "docs/v2/qa/2744-final-required-ci-matrix.md"],
            "test/2744-v2-final-required-ci-matrix",
        )
        self.assertIn("v2-g003-full-matrix",p["branch_rules"])
        for area in ("backend","customer","driver","van","dashboard_ui","mobile_ui"):
            self.assertTrue(p["areas"][area],area)
        self.assertFalse(p["backend_dashboard_only"])

    def test_v2_g003_branch_is_exact_and_does_not_widen_other_workers(self):
        """Normal V2 backend-only PRs must not build unrelated mobile apps."""
        names=("feat/2744-unrelated","test/2745-v2-final-required-ci-matrix",
               "test/2744-v2-final-required-ci-matrix-retry",
               "feat/2678-v2-store-permission-audit")
        for branch in names:
            with self.subTest(branch=branch):
                p=module.resolve(self.config,["backend/app/Services/OrderService.php"],branch)
                self.assertNotIn("v2-g003-full-matrix",p["branch_rules"])
                self.assertTrue(p["backend_dashboard_only"])
                for area in ("customer","driver","van"):
                    self.assertFalse(p["areas"][area],area)

    def test_v2_g003_all_four_validation_jobs_are_real_required_gate_dependencies(self):
        """A runtime named elsewhere in YAML must not evade the terminal CI gate."""
        import re

        workflow=(ROOT/".github/workflows/required-ci-gate.yml").read_text(encoding="utf-8")

        def job_body(name):
            marker=f"  {name}:\n"
            self.assertIn(marker,workflow)
            body=workflow.split(marker,1)[1]
            next_job=re.search(r"(?m)^  [a-z][a-z0-9-]*:\s*$",body)
            return body[:next_job.start()] if next_job else body

        gate=job_body("required-ci-gate")
        match=re.search(r"(?m)^    needs:\n((?:      - [a-z][a-z0-9-]*\n)+)",gate)
        self.assertIsNotNone(match,"Terminal required-ci-gate lacks a real needs list")
        needs=match.group(1)
        workflows={
            "backend":"backend-ci.yml",
            "customer":"customer-app-ci.yml",
            "driver":"driver-app-ci.yml",
            "van":"van-app-ci.yml",
        }
        for area,called in workflows.items():
            with self.subTest(area=area):
                job=job_body(f"{area}-validation")
                self.assertIn("needs: changes",job)
                self.assertIn(f"needs.changes.outputs.{area} == 'true'",job)
                self.assertIn(f"uses: ./.github/workflows/{called}",job)
                self.assertIn(f"      - {area}-validation\n",needs)
                self.assertIn(f"{area.upper()}_RESULT:",gate)
                self.assertIn(f'require_applicable_success "${area.upper()}_CHANGED"',gate)
        self.assertIn("!cancelled()",gate)

    def test_final_convergence_branch_can_explicitly_restore_mobile_lanes(self):
        p=module.resolve(self.config,["backend/app/Services/OrderService.php"],"test/1253-operational-completion-e2e")
        self.assertFalse(p["backend_dashboard_only"])
        for area in ("customer","driver","van"):
            self.assertTrue(p["areas"][area],area)

    def test_v2_ci_routing_matrix_and_unknown_paths_fail_closed(self):
        cases={
            "dashboard-only": (["backend/resources/views/admin/orders/index.blade.php"], ("backend","dashboard_ui"), ("customer","driver","van")),
            "backend-only": (["backend/app/Services/OrderService.php"], ("backend",), ("customer","driver","van")),
            "customer-only": (["apps/customer_app/lib/features/orders/orders_screen.dart"], ("customer","mobile_ui"), ("backend","driver","van")),
            "driver-only": (["apps/driver_app/lib/features/navigation/navigation_page.dart"], ("driver","mobile_ui"), ("backend","customer","van")),
            "van-only": (["apps/van_app/lib/features/orders/van_order_screen.dart"], ("van","mobile_ui"), ("backend","customer","driver")),
            "shared-flutter-dependency": (["packages/design_tokens/lib/src/colors.dart"], ("customer","driver","van","mobile_ui"), ("backend",)),
            "finance-rbac-security": (["backend/app/Services/Finance/PaymentApprovalService.php","backend/app/Http/Middleware/EffectivePermission.php"], ("backend",), ("customer","driver","van")),
            "installer-updater-zip": (["scripts/validate_release_artifact_contract.py"], ("backend","customer","driver","van"), ()),
            "ci-configuration": ([".github/workflows/backend-ci.yml"], ("backend","customer","driver","van","mobile_ui","enterprise_performance"), ()),
        }
        for name,(paths,required,forbidden) in cases.items():
            with self.subTest(scenario=name):
                plan=module.resolve(self.config,paths,"fix/4013-composer-ci-cache")
                self.assertEqual([],plan["unclassified_paths"])
                for area in required: self.assertTrue(plan["areas"][area],area)
                for area in forbidden: self.assertFalse(plan["areas"][area],area)
                if name=="ci-configuration": self.assertFalse(plan["backend_dashboard_only"])
        for malformed in (
            "unknown/path.txt",
            "docs/v2/qa/unreviewed-security-contract.md",
            "../backend/app/Services/Finance.php",
            "",
        ):
            with self.subTest(scenario="malformed-or-unknown",path=malformed):
                plan=module.resolve(self.config,[malformed],"fix/4013-composer-ci-cache")
                self.assertEqual([malformed],plan["unclassified_paths"])
                for area in self.config["areas"]: self.assertTrue(plan["areas"][area],area)
                self.assertFalse(plan["backend_dashboard_only"])

    def test_required_laravel_jobs_cache_exact_composer_vendor_with_install_fallback(self):
        workflow=(ROOT/".github/workflows/backend-ci.yml").read_text(encoding="utf-8")
        self.assertEqual(3,workflow.count("- name: Cache installed Composer dependencies"))
        self.assertEqual(3,workflow.count("composer-vendor-v1-${{ runner.os }}-php-8.3-${{ hashFiles('backend/composer.json', 'backend/composer.lock') }}"))
        self.assertEqual(3,workflow.count("if [[ ! -f vendor/autoload.php ]]; then"))
        self.assertEqual(3,workflow.count("composer install --no-interaction --prefer-dist --no-progress"))
        self.assertNotIn("restore-keys:",workflow.split("- name: Cache installed Composer dependencies",1)[1].split("- name:",1)[0])

    @staticmethod
    def _git(repo:Path,*args:str)->str:
        return subprocess.check_output(
            ["git","-C",str(repo),*args],
            text=True,
            stderr=subprocess.PIPE,
        ).strip()

    def _init_test_repo(self,repo:Path)->None:
        self._git(repo,"init","--quiet")
        self._git(repo,"config","user.name","CI planner regression")
        self._git(repo,"config","user.email","ci-planner@example.invalid")

    def test_changed_files_matches_merge_base_for_outdated_and_rebased_pull_requests(self):
        previous_root=module.ROOT
        try:
            with tempfile.TemporaryDirectory() as directory:
                repo=Path(directory)
                self._init_test_repo(repo)
                (repo/"common.txt").write_text("common\n",encoding="utf-8")
                self._git(repo,"add","common.txt")
                self._git(repo,"commit","-m","common base")
                self._git(repo,"branch","-m","integration")
                self._git(repo,"switch","-c","pull-request")
                (repo/"pull-request.txt").write_text("pr change\n",encoding="utf-8")
                self._git(repo,"add","pull-request.txt")
                self._git(repo,"commit","-m","pull request change")
                head=self._git(repo,"rev-parse","HEAD")

                self._git(repo,"switch","integration")
                (repo/"base-only.txt").write_text("target branch change\n",encoding="utf-8")
                self._git(repo,"add","base-only.txt")
                self._git(repo,"commit","-m","advance integration")
                advanced_base=self._git(repo,"rev-parse","HEAD")
                module.ROOT=repo

                legacy=module.git("diff","--name-only",advanced_base,head).splitlines()
                self.assertEqual(["base-only.txt","pull-request.txt"],sorted(legacy))
                self.assertEqual(["pull-request.txt"],module.changed_files(advanced_base,head))

                self._git(repo,"switch","pull-request")
                self._git(repo,"rebase","integration")
                rebased_head=self._git(repo,"rev-parse","HEAD")
                self.assertEqual(["pull-request.txt"],module.changed_files(advanced_base,rebased_head))
        finally:
            module.ROOT=previous_root

    def test_changed_files_fails_closed_when_no_merge_base_exists(self):
        previous_root=module.ROOT
        try:
            with tempfile.TemporaryDirectory() as directory:
                repo=Path(directory)
                self._init_test_repo(repo)
                (repo/"base.txt").write_text("first root\n",encoding="utf-8")
                self._git(repo,"add","base.txt")
                self._git(repo,"commit","-m","first root")
                base=self._git(repo,"rev-parse","HEAD")

                self._git(repo,"switch","--orphan","unrelated")
                (repo/"base.txt").unlink(missing_ok=True)
                (repo/"unrelated.txt").write_text("unrelated root\n",encoding="utf-8")
                self._git(repo,"add","--all")
                self._git(repo,"commit","-m","unrelated root")
                head=self._git(repo,"rev-parse","HEAD")
                module.ROOT=repo

                with self.assertRaisesRegex(ValueError,"Cannot compute merge-base diff"):
                    module.changed_files(base,head)
        finally:
            module.ROOT=previous_root

    def test_reviewed_v2_qa_documents_route_to_minimum_relevant_areas(self):
        routes={
            "docs/v2/qa/3960-erp-center-horizontal-tabs.md":{"backend","dashboard_ui"},
            "docs/v2/qa/3951-enterprise-bi-page-tabs-authority.md":{"backend","dashboard_ui"},
            "docs/v2/qa/4007-treasury-page-tab-rail.md":{"backend","dashboard_ui"},
            "docs/v2/qa/4000-archived-active-role-authority.md":{"backend"},
            "docs/v2/qa/2732-wholesale-financial-parity.md":{"backend","customer"},
            "docs/v2/qa/2845-erp-entitlement-maker-checker-boundaries.md":{"backend"},
            "docs/v2/qa/2559-profitability-rule-authority.md":{"backend","dashboard_ui","enterprise_performance"},
        }
        for path,expected in routes.items():
            with self.subTest(path=path):
                plan=module.resolve(self.config,[path],"ci/4016-ci-merge-base-routing")
                actual={area for area,enabled in plan["areas"].items() if enabled}
                self.assertEqual([],plan["unclassified_paths"])
                self.assertEqual(expected,actual)
                self.assertNotIn("c13_integrated_e2e",actual)
                self.assertNotIn("operational_completion_e2e",actual)
                self.assertEqual("customer" not in expected,plan["backend_dashboard_only"])

    def test_c13_and_operational_gates_keep_their_existing_branch_scope(self):
        ordinary=module.resolve(
            self.config,
            ["apps/customer_app/test/b2b_journey_test.dart","docs/v2/qa/2732-wholesale-financial-parity.md"],
            "feat/2732-v2-customer-wholesale-commerce-parity",
        )
        self.assertFalse(ordinary["areas"]["c13_integrated_e2e"])
        self.assertFalse(ordinary["areas"]["operational_completion_e2e"])

        c13=module.resolve(self.config,[],"test/873-c13-integrated-e2e")
        self.assertTrue(c13["areas"]["c13_integrated_e2e"])
        self.assertTrue(c13["areas"]["operational_completion_e2e"])

    def test_known_issue_notes_route_to_policy_without_widening_unknown_paths(self):
        known=module.resolve(self.config,[".ai/KNOWN_ISSUES.md"],"ci/4016-ci-merge-base-routing")
        self.assertEqual([],known["unclassified_paths"])
        self.assertEqual({"policy"},{area for area,enabled in known["areas"].items() if enabled})

        unknown=module.resolve(
            self.config,
            ["docs/v2/qa/unreviewed-security-contract.md"],
            "ci/4016-ci-merge-base-routing",
        )
        self.assertEqual(["docs/v2/qa/unreviewed-security-contract.md"],unknown["unclassified_paths"])
        for area in self.config["areas"]:
            self.assertTrue(unknown["areas"][area],area)

if __name__=="__main__": unittest.main()
