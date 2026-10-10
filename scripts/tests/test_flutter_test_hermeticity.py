from __future__ import annotations
import importlib.util, sys, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("flutter_test_hermeticity",ROOT/"scripts/flutter-test-hermeticity.py")
module=importlib.util.module_from_spec(spec); assert spec and spec.loader
sys.modules[spec.name]=module; spec.loader.exec_module(module)

class HermeticityTest(unittest.TestCase):
    def test_current_flutter_tests_have_no_direct_network_primitives(self):
        self.assertEqual([],module.scan())
if __name__=="__main__": unittest.main()
