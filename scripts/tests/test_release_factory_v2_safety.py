from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/release-factory.yml"


class ReleaseFactoryV2SafetyTest(unittest.TestCase):
    def test_publish_input_is_rejected_outside_main(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        plan_start = workflow.index("      - id: plan\n")
        dashboard_start = workflow.index("  dashboard-only:\n", plan_start)
        plan = workflow[plan_start:dashboard_start]

        self.assertIn("REQUESTED_PUBLISH: ${{ inputs.publish }}", plan)
        self.assertIn('"$GITHUB_EVENT_NAME" == "workflow_dispatch"', plan)
        self.assertIn('"${REQUESTED_PUBLISH,,}" == "true"', plan)
        self.assertIn('"$GITHUB_REF" != "refs/heads/main"', plan)
        self.assertIn("V2 validation runs must remain nonpublishing", plan)

    def test_dashboard_only_main_dispatch_is_main_release_only(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        dashboard_start = workflow.index("  dashboard-only:\n")
        mobile_start = workflow.index("\n  mobile:\n", dashboard_start)
        job = workflow[dashboard_start:mobile_start]

        self.assertIn("github.ref == 'refs/heads/main'", job)
        self.assertIn("github.event_name == 'push' || inputs.publish", job)
        self.assertIn("gh workflow run release-update-bundle.yml --ref main", job)


if __name__ == "__main__":
    unittest.main()
