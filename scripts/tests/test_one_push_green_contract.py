from __future__ import annotations
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class OnePushGreenContractTest(unittest.TestCase):
    def test_required_gate_uses_authoritative_ci_plan(self):
        text = (ROOT / ".github/workflows/required-ci-gate.yml").read_text(encoding="utf-8")
        self.assertIn("scripts/ci-plan.py", text)
        self.assertNotIn("grep -Eq '^(backend/", text)

    def test_local_detector_is_thin_ci_plan_wrapper(self):
        text = (ROOT / "scripts/detect-changed-areas.sh").read_text(encoding="utf-8")
        self.assertIn("scripts/ci-plan.py", text)
        self.assertNotIn("backend=false", text)

    def test_preflight_has_ci_parity_van_and_strict_flutter_diff_analysis(self):
        text = (ROOT / "scripts/worker-preflight.sh").read_text(encoding="utf-8")
        self.assertIn("--ci-parity", text)
        self.assertIn("run_flutter van", text)
        self.assertIn("vendor/bin/pint", text)
        self.assertIn("composer analyse", text)
        self.assertIn("flutter-changed-analysis.sh", text)

    def test_flutter_changed_analysis_is_format_and_fatal_info_strict(self):
        text = (ROOT / "scripts/flutter-changed-analysis.sh").read_text(encoding="utf-8")
        self.assertIn("dart format --output=none --set-exit-if-changed", text)
        self.assertIn("dart analyze --fatal-infos", text)
        self.assertIn("use_build_context_synchronously", text)
        self.assertIn("unawaited_futures", text)
        self.assertIn("cancel_subscriptions", text)
        self.assertIn("close_sinks", text)

    def test_flutter_analysis_options_are_strict_and_in_sync(self):
        paths = [
            ROOT / "apps/customer_app/analysis_options.yaml",
            ROOT / "apps/driver_app/analysis_options.yaml",
            ROOT / "apps/van_app/analysis_options.yaml",
        ]
        texts = [path.read_text(encoding="utf-8") for path in paths]
        self.assertTrue(all(text == texts[0] for text in texts[1:]))
        for rule in (
            "avoid_void_async: true",
            "cancel_subscriptions: true",
            "close_sinks: true",
            "prefer_final_fields: true",
            "prefer_final_locals: true",
            "unawaited_futures: true",
            "use_build_context_synchronously: true",
        ):
            self.assertIn(rule, texts[0])

    def test_each_flutter_ci_runs_strict_changed_file_analysis(self):
        for app in ("customer", "driver", "van"):
            path = ROOT / f".github/workflows/{app}-app-ci.yml"
            text = path.read_text(encoding="utf-8")
            self.assertIn("fetch-depth: 0", text, path.name)
            self.assertIn(
                f"flutter-changed-analysis.sh {app}",
                text,
                path.name,
            )

    def test_full_tree_flutter_analysis_keeps_legacy_infos_nonfatal(self):
        for path in (
            ROOT / ".github/workflows/customer-app-ci.yml",
            ROOT / ".github/workflows/driver-app-ci.yml",
            ROOT / ".github/workflows/van-app-ci.yml",
            ROOT / ".github/workflows/driver-preview-web-ci.yml",
            ROOT / "scripts/worker-preflight.sh",
            ROOT / "scripts/bootstrap-flutter.sh",
        ):
            text = path.read_text(encoding="utf-8")
            self.assertIn("flutter analyze --no-fatal-infos", text, str(path))

    def test_required_policy_executes_skill_router_regressions(self):
        text = (ROOT / ".github/workflows/required-ci-gate.yml").read_text(encoding="utf-8")
        self.assertIn("test_foodex_skill_router.py", text)

    def test_localization_mobile_runtime_is_path_scoped(self):
        gate = (ROOT / ".github/workflows/required-ci-gate.yml").read_text(encoding="utf-8")
        localization = (ROOT / ".github/workflows/localization-quality-gate.yml").read_text(encoding="utf-8")
        self.assertIn("run_mobile_runtime:", gate)
        self.assertIn("backend_dashboard_only != 'true'", gate)
        self.assertIn("BACKEND_DASHBOARD_ONLY: ${{ needs.changes.outputs.backend_dashboard_only }}", gate)
        self.assertIn('if [[ "$BACKEND_DASHBOARD_ONLY" == "true" ]]; then', gate)
        self.assertIn("CUSTOMER_CHANGED=false", gate)
        self.assertIn("DRIVER_CHANGED=false", gate)
        self.assertIn("VAN_CHANGED=false", gate)
        self.assertGreaterEqual(localization.count("inputs.run_mobile_runtime || github.event_name == 'workflow_dispatch'"), 4)

    def test_redocly_is_version_pinned(self):
        text = (ROOT / ".github/workflows/backend-ci.yml").read_text(encoding="utf-8")
        self.assertNotIn("@redocly/cli@latest", text)
        self.assertIn("@redocly/cli@2.59.0", text)

    def test_github_node_actions_are_node24_generation(self):
        for path in (ROOT / ".github/workflows").glob("*.yml"):
            text = path.read_text(encoding="utf-8")
            self.assertNotIn("actions/checkout@v4", text, path.name)
            self.assertNotIn("actions/cache@v4", text, path.name)
            self.assertNotIn("actions/setup-node@v4", text, path.name)
            self.assertNotIn("@latest", text, path.name)


if __name__ == "__main__":
    unittest.main()
