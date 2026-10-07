from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "validate-active-mission.py"
spec = importlib.util.spec_from_file_location("validate_active_mission", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)


def valid_registry():
    return {
        "schema_version": 1,
        "mission_id": "TEST",
        "active": True,
        "umbrella_issue": 10,
        "integration_target": "release/1.2.3-test",
        "max_active_lanes": 6,
        "status_source": "live",
        "owner_commands": ["FOOD MISSION"],
        "children": [
            {"issue": 11, "branch": "feat/11-first-lane", "depends_on": []},
            {"issue": 12, "branch": "test/12-final-gate", "depends_on": [11]},
        ],
        "final_gate_issue": 12,
        "main_merge_allowed": False,
    }


class ActiveMissionRegistryTest(unittest.TestCase):
    def test_valid_registry_passes(self):
        self.assertEqual([], module.validate_registry(valid_registry()))

    def test_schema_v2_registry_passes(self):
        data = valid_registry()
        data["schema_version"] = 2
        self.assertEqual([], module.validate_registry(data))

    def test_unsupported_schema_version_fails(self):
        data = valid_registry()
        data["schema_version"] = 3
        errors = module.validate_registry(data)
        self.assertTrue(any("schema_version must be one of: 1, 2" in e for e in errors))

    def test_release_branch_with_matching_issue_is_valid(self):
        data = valid_registry()
        data["children"][1]["branch"] = "release/12-final-real-build"
        self.assertEqual([], module.validate_registry(data))

    def test_duplicate_branch_fails(self):
        data = valid_registry()
        data["children"][1]["branch"] = "feat/11-first-lane"
        errors = module.validate_registry(data)
        self.assertTrue(any("duplicate canonical branch" in e for e in errors))

    def test_branch_issue_number_must_match(self):
        data = valid_registry()
        data["children"][0]["branch"] = "feat/99-first-lane"
        errors = module.validate_registry(data)
        self.assertTrue(any("embeds issue #99" in e for e in errors))

    def test_dependency_cycle_fails(self):
        data = valid_registry()
        data["children"][0]["depends_on"] = [12]
        errors = module.validate_registry(data)
        self.assertTrue(any("dependency cycle" in e for e in errors))

    def test_unknown_dependency_fails(self):
        data = valid_registry()
        data["children"][1]["depends_on"] = [999]
        errors = module.validate_registry(data)
        self.assertTrue(any("unknown mission child #999" in e for e in errors))

    def test_more_than_six_active_lanes_is_invalid(self):
        data = valid_registry()
        data["max_active_lanes"] = 7
        errors = module.validate_registry(data)
        self.assertTrue(any("between 1 and 6" in e for e in errors))


if __name__ == "__main__":
    unittest.main()
