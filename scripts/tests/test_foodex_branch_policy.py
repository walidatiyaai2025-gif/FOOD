from __future__ import annotations
import importlib.util, sys, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("foodex_branch_policy",ROOT/"scripts/foodex-branch-policy.py")
module=importlib.util.module_from_spec(spec); assert spec and spec.loader
sys.modules[spec.name]=module; spec.loader.exec_module(module)

class BranchPolicyTest(unittest.TestCase):
    def test_generates_issue_scoped_branch(self):
        self.assertEqual("fix/1253-customer-network-storm-release",module.build_name(1253,"fix","Customer network storm release"))
    def test_rejects_missing_issue(self): self.assertFalse(module.is_valid("fix/customer-network-storm-release"))
    def test_accepts_issue_scoped(self): self.assertTrue(module.is_valid("ci/1253-one-push-green"))
    def test_accepts_program_worker_branch(self):
        self.assertTrue(module.is_valid("worker/1381-customer-freeze-release-contract","release/1.0.77-production-program"))
    def test_main_only_release_baseline(self):
        self.assertTrue(module.is_valid("main","release/1.0.68-update"))
        self.assertFalse(module.is_valid("main","main"))

    def test_main_push_can_be_explicitly_allowed(self):
        self.assertTrue(module.is_valid("main", allow_main=True))

    def test_accepts_canonical_integration_promotion_to_main(self):
        for branch in [
            "feature/enterprise-core-platform",
            "feature/enterprise-admin-shell",
            "feature/enterprise-procurement",
            "feature/enterprise-wms",
            "feature/enterprise-finance",
            "feature/enterprise-treasury",
            "feature/enterprise-commercial-convergence",
            "feature/enterprise-bi",
            "feature/enterprise-performance-gates",
        ]:
            with self.subTest(branch=branch):
                self.assertTrue(module.is_valid(branch,"main"))

    def test_accepts_main_sync_to_issue_scoped_branch(self):
        for branch in [
            "feat/1978-catalog-product-commercial-completion",
            "fix/2060-main-to-issue-sync-policy",
            "ci/2060-main-sync-policy-regression",
        ]:
            with self.subTest(branch=branch):
                self.assertTrue(module.is_valid("main", branch))

    def test_rejects_main_sync_to_non_issue_branch(self):
        for branch in [
            "feature/random-unregistered-program",
            "fix/main-sync-without-issue",
            "main",
        ]:
            with self.subTest(branch=branch):
                self.assertFalse(module.is_valid("main", branch))

    def test_accepts_main_sync_only_to_canonical_integration_branch(self):
        for branch in [
            "feature/enterprise-core-platform",
            "feature/enterprise-admin-shell",
            "feature/enterprise-procurement",
            "feature/enterprise-wms",
            "feature/enterprise-finance",
            "feature/enterprise-treasury",
            "feature/enterprise-commercial-convergence",
            "feature/enterprise-bi",
            "feature/enterprise-performance-gates",
        ]:
            with self.subTest(branch=branch):
                self.assertTrue(module.is_valid("main",branch))
        self.assertFalse(module.is_valid("main","feature/random-unregistered-program"))

    def test_rejects_integration_branch_outside_main_promotion(self):
        self.assertFalse(module.is_valid("feature/enterprise-performance-gates"))
        self.assertFalse(module.is_valid("feature/enterprise-performance-gates","feature/enterprise-core-platform"))
        self.assertFalse(module.is_valid("feature/random-unregistered-program","main"))
if __name__=="__main__": unittest.main()
