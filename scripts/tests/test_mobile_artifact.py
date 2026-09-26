import hashlib
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "package-mobile-artifact.py"
SPEC = importlib.util.spec_from_file_location("mobile_artifact", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class MobileArtifactTest(unittest.TestCase):
    def test_both_apps_and_platforms_produce_traceable_validation_evidence(self):
        for app in ("customer", "driver"):
            for platform, suffix in (("android", ".apk"), ("ios", ".zip")):
                with self.subTest(app=app, platform=platform), tempfile.TemporaryDirectory() as directory:
                    root = Path(directory)
                    app_path = root / "apps" / f"{app}_app"
                    app_path.mkdir(parents=True)
                    (app_path / "pubspec.yaml").write_text("version: 1.2.3+1\n")
                    (root / "VERSION").write_text("2.0.0\n")
                    source = root / f"input{suffix}"
                    source.write_bytes(b"test artifact contents")
                    manifest = MODULE.package(root, app, platform, source, root / "out", "a" * 40, "42")
                    self.assertEqual("1.2.3", manifest["mobile_version"])
                    self.assertEqual("42", manifest["build_number"])
                    self.assertEqual("a" * 40, manifest["source_commit"])
                    self.assertFalse(manifest["production_ready"])
                    self.assertEqual("ci-validation-only", manifest["purpose"])
                    self.assertTrue(manifest["api_base_url"].endswith(".invalid"))
                    self.assertEqual("flutter-template-debug-key" if platform == "android" else "none", manifest["signing"])
                    self.assertEqual(hashlib.sha256(source.read_bytes()).hexdigest(), manifest["sha256"])
                    self.assertEqual(manifest, json.loads((root / "out/manifest.json").read_text()))
                    self.assertIn(manifest["sha256"], (root / "out/SHA256SUMS").read_text())
                    self.assertEqual(source.read_bytes(), (root / "out" / manifest["artifact"]).read_bytes())

    def test_invalid_inputs_fail_before_creating_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "input.apk"
            for data, sha, build in ((None, "a" * 40, "1"), (b"", "a" * 40, "1"), (b"x", "main", "1"), (b"x", "a" * 40, "0")):
                if data is not None:
                    source.write_bytes(data)
                with self.assertRaises(ValueError):
                    MODULE.package(root, "customer", "android", source, root / "out", sha, build)
                self.assertFalse((root / "out").exists())


if __name__ == "__main__":
    unittest.main()
