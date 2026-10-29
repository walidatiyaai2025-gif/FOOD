from __future__ import annotations
import unittest
import hashlib
import json
import os
import subprocess
import tempfile
import zipfile
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



class PublishedDashboardSyncPolicyTest(unittest.TestCase):
    """Exercise the exact release artifact lane without weakening other release branches."""

    def _git(self, repo: Path, *args: str) -> str:
        return subprocess.check_output(["git", "-C", str(repo), *args], text=True).strip()

    def _fixture(self, repo: Path, *, stable_files_manifest: bool = False) -> tuple[str, str]:
        self._git(repo, "init", "-q")
        self._git(repo, "config", "user.email", "ci@example.test")
        self._git(repo, "config", "user.name", "CI Fixture")
        (repo / "VERSION").write_text("1.0.118\n", encoding="utf-8")
        for app in ("customer_app", "driver_app", "van_app"):
            pubspec = repo / "apps" / app / "pubspec.yaml"
            pubspec.parent.mkdir(parents=True)
            pubspec.write_text("name: test\nversion: 1.0.118+118\n", encoding="utf-8")
        registry = repo / "docs/release/RELEASE_REGISTRY.json"
        registry.parent.mkdir(parents=True)
        baseline = {"schema_version": 1, "current_version": "1.0.117", "releases": [
            {"version": "1.0.117", "state": "published"}
        ]}
        registry.write_text(json.dumps(baseline), encoding="utf-8")
        if stable_files_manifest:
            # Generated delta inventory may be byte-identical to prior release.
            index = repo / "Release/Updates/FOODEX-Update.files.txt"
            index.parent.mkdir(parents=True, exist_ok=True)
            index.write_text("README.txt\n", encoding="utf-8")
        self._git(repo, "add", ".")
        self._git(repo, "commit", "-qm", "baseline")
        base = self._git(repo, "rev-parse", "HEAD")

        updates = repo / "Release/Updates"
        updates.mkdir(parents=True)
        package = updates / "FOODEX-Update.zip"
        with zipfile.ZipFile(package, "w") as archive:
            archive.writestr("README.txt", "published Dashboard update fixture")
        digest = hashlib.sha256(package.read_bytes()).hexdigest()
        (updates / "FOODEX-Update.sha256.txt").write_text(
            f"{digest}  FOODEX-Update.zip\n", encoding="utf-8"
        )
        (updates / "FOODEX-Update.files.txt").write_text("README.txt\n", encoding="utf-8")
        manifest = {
            "available": True, "package": "FOODEX-Update.zip",
            "target_version": "1.0.118",
            "minimum_current_version": "1.0.117",
            "source_commit": base, "sha256": digest,
            "delta": {"files_manifest": "FOODEX-Update.files.txt"},
            "dashboard_fields": {"target_version": "1.0.118", "sha256": digest},
        }
        (updates / "FOODEX-Update.json").write_text(json.dumps(manifest), encoding="utf-8")
        promoted = {
            "schema_version": 1, "current_version": "1.0.118",
            "releases": [*baseline["releases"], {
                "version": "1.0.118", "state": "published",
                "registration": "generated-release",
                "dashboard_update_sha256": digest,
            }],
        }
        registry.write_text(json.dumps(promoted), encoding="utf-8")
        self._git(repo, "add", ".")
        self._git(repo, "commit", "-qm", "generated artifacts")
        return base, self._git(repo, "rev-parse", "HEAD")

    def _validate(self, repo: Path, base: str, head: str, branch: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            ["bash", str(ROOT / "scripts/validate-premerge-release-version.sh"), base, head],
            cwd=repo,
            env={**os.environ, "GITHUB_HEAD_REF": branch, "GITHUB_BASE_REF": "main"},
            capture_output=True, text=True, check=False,
        )

    def test_unchanged_generated_files_index_is_allowed(self):
        with tempfile.TemporaryDirectory() as location:
            repo = Path(location)
            base, head = self._fixture(repo, stable_files_manifest=True)
            changed = self._git(repo, "diff", "--name-only", base, head).splitlines()
            self.assertNotIn("Release/Updates/FOODEX-Update.files.txt", changed)
            for required in (
                "Release/Updates/FOODEX-Update.zip",
                "Release/Updates/FOODEX-Update.json",
                "docs/release/RELEASE_REGISTRY.json",
            ):
                self.assertIn(required, changed)
            result = self._validate(repo, base, head, "release/1.0.118-dashboard-update-sync")
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_published_artifact_sync_and_negative_boundaries(self):
        with tempfile.TemporaryDirectory() as location:
            repo = Path(location)
            base, head = self._fixture(repo)
            branch = "release/1.0.118-dashboard-update-sync"
            allowed = self._validate(repo, base, head, branch)
            self.assertEqual(0, allowed.returncode, allowed.stdout + allowed.stderr)

            wrong_branch = self._validate(repo, base, head, "release/1.0.118-unrelated")
            self.assertNotEqual(0, wrong_branch.returncode)
            wrong_version = self._validate(
                repo, base, head, "release/1.0.119-dashboard-update-sync"
            )
            self.assertNotEqual(0, wrong_version.returncode)

            unexpected = repo / "backend/app/Unrelated.php"
            unexpected.parent.mkdir(parents=True)
            unexpected.write_text("<?php\n", encoding="utf-8")
            self._git(repo, "add", ".")
            self._git(repo, "commit", "-qm", "unexpected source change")
            changed_head = self._git(repo, "rev-parse", "HEAD")
            outside = self._validate(repo, base, changed_head, branch)
            self.assertNotEqual(0, outside.returncode)
            self.assertIn("only the five certified generated paths", outside.stderr)

            self._git(repo, "reset", "--hard", head)
            hashfile = repo / "Release/Updates/FOODEX-Update.sha256.txt"
            hashfile.write_text("0" * 64 + "  FOODEX-Update.zip\n", encoding="utf-8")
            self._git(repo, "add", ".")
            self._git(repo, "commit", "-qm", "invalid hash")
            corrupt_head = self._git(repo, "rev-parse", "HEAD")
            corrupt = self._validate(repo, base, corrupt_head, branch)
            self.assertNotEqual(0, corrupt.returncode)
            self.assertIn("hashes must match", corrupt.stderr)


if __name__ == "__main__":
    unittest.main()
