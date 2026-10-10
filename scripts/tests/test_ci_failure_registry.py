from __future__ import annotations
import importlib.util, sys, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("foodex_ci_failure_registry",ROOT/"scripts/ci-failure-registry.py")
module=importlib.util.module_from_spec(spec); assert spec and spec.loader
sys.modules[spec.name]=module; spec.loader.exec_module(module)

class RegistryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls): cls.data=module.load()
    def test_valid(self): self.assertEqual([],module.validate(self.data))
    def test_phpstan_classifies(self):
        self.assertIn("phpstan-type-drift",module.classify(self.data,"composer analyse\n[ERROR] Found 6 errors\nCannot call method toISOString() on string."))
    def test_bad_branch_classifies(self):
        self.assertIn("invalid-issue-branch",module.classify(self.data,"Invalid issue branch name: fix/customer-network-storm-release"))
if __name__=="__main__": unittest.main()
