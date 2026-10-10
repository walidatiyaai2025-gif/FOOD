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
    def test_main_only_release_baseline(self):
        self.assertTrue(module.is_valid("main","release/1.0.68-update")); self.assertFalse(module.is_valid("main","main"))
if __name__=="__main__": unittest.main()
