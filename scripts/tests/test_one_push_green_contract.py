from __future__ import annotations
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]

class OnePushGreenContractTest(unittest.TestCase):
    def test_required_gate_uses_authoritative_ci_plan(self):
        text=(ROOT/".github/workflows/required-ci-gate.yml").read_text(encoding="utf-8")
        self.assertIn("scripts/ci-plan.py",text)
        self.assertNotIn("grep -Eq '^(backend/",text)

    def test_local_detector_is_thin_ci_plan_wrapper(self):
        text=(ROOT/"scripts/detect-changed-areas.sh").read_text(encoding="utf-8")
        self.assertIn("scripts/ci-plan.py",text)
        self.assertNotIn("backend=false",text)

    def test_preflight_has_ci_parity_and_van(self):
        text=(ROOT/"scripts/worker-preflight.sh").read_text(encoding="utf-8")
        self.assertIn("--ci-parity",text)
        self.assertIn('run_flutter van',text)
        self.assertIn('vendor/bin/pint',text)
        self.assertIn('composer analyse',text)

    def test_redocly_is_version_pinned(self):
        text=(ROOT/".github/workflows/backend-ci.yml").read_text(encoding="utf-8")
        self.assertNotIn("@redocly/cli@latest",text)
        self.assertIn("@redocly/cli@2.59.0",text)

    def test_github_node_actions_are_node24_generation(self):
        for path in (ROOT/".github/workflows").glob("*.yml"):
            text=path.read_text(encoding="utf-8")
            self.assertNotIn("actions/checkout@v4",text,path.name)
            self.assertNotIn("actions/cache@v4",text,path.name)
            self.assertNotIn("actions/setup-node@v4",text,path.name)
            self.assertNotIn("@latest",text,path.name)

if __name__=="__main__":
    unittest.main()
