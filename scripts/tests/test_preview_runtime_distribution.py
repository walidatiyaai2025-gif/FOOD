import json
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "package-preview-runtimes.py"


class PreviewRuntimeDistributionTest(unittest.TestCase):
    def _build_fixture(self, root: Path, base_href: str) -> Path:
        root.mkdir(parents=True)
        (root / "index.html").write_text(
            f'<html><head><base href="{base_href}"></head><body></body></html>',
            encoding="utf-8",
        )
        (root / "main.dart.js").write_text("console.log('runtime');", encoding="utf-8")
        (root / "flutter_bootstrap.js").write_text("console.log('boot');", encoding="utf-8")
        assets = root / "assets"
        assets.mkdir()
        (assets / "AssetManifest.bin").write_bytes(b"manifest")
        return root

    def _run(self, workspace: Path, output: Path) -> subprocess.CompletedProcess[str]:
        customer = self._build_fixture(workspace / "customer", "/preview/customer/")
        driver = self._build_fixture(workspace / "driver", "/preview/driver/")
        return subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--customer-build",
                str(customer),
                "--driver-build",
                str(driver),
                "--output-dir",
                str(output),
                "--version",
                "1.0.40",
                "--commit",
                "0123456789abcdef0123456789abcdef01234567",
                "--api-base-url",
                "https://foodex.50sols.com",
                "--parent-origin",
                "https://foodex.50sols.com",
                "--runtime-origin",
                "https://foodex.50sols.com",
            ],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )

    def test_packages_both_runtime_targets_with_manifest_and_env(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            result = self._run(workspace / "input", workspace / "out")
            self.assertEqual(result.returncode, 0, result.stderr)

            out = workspace / "out"
            manifest = json.loads((out / "preview-runtime-manifest.json").read_text())
            self.assertEqual(manifest["schema"], "foodex.preview-runtime-distribution.v1")
            self.assertEqual(manifest["contract_version"], "shared-flutter-v1")
            self.assertEqual(
                manifest["runtimes"]["customer"]["runtime_url"],
                "https://foodex.50sols.com/preview/customer/",
            )
            self.assertEqual(
                manifest["runtimes"]["driver"]["runtime_url"],
                "https://foodex.50sols.com/preview/driver/",
            )

            for runtime in ("customer", "driver"):
                archive = out / manifest["runtimes"][runtime]["archive"]["file"]
                self.assertTrue(archive.is_file())
                self.assertTrue(manifest["runtimes"][runtime]["archive"]["sha256"])
                with tarfile.open(archive, "r:gz") as handle:
                    names = set(handle.getnames())
                self.assertIn(f"{runtime}/index.html", names)
                self.assertIn(f"{runtime}/main.dart.js", names)
                self.assertIn(f"{runtime}/flutter_bootstrap.js", names)

            env = (out / "preview-runtime.env").read_text()
            self.assertIn(
                "FOODEX_CUSTOMER_PREVIEW_RUNTIME_URL=https://foodex.50sols.com/preview/customer/",
                env,
            )
            self.assertIn(
                "FOODEX_DRIVER_PREVIEW_RUNTIME_URL=https://foodex.50sols.com/preview/driver/",
                env,
            )

    def test_archive_identity_is_deterministic_for_same_input(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            first = self._run(workspace / "one-input", workspace / "one-out")
            second = self._run(workspace / "two-input", workspace / "two-out")
            self.assertEqual(first.returncode, 0, first.stderr)
            self.assertEqual(second.returncode, 0, second.stderr)

            one = json.loads((workspace / "one-out" / "preview-runtime-manifest.json").read_text())
            two = json.loads((workspace / "two-out" / "preview-runtime-manifest.json").read_text())
            self.assertEqual(
                one["runtimes"]["customer"]["archive"]["sha256"],
                two["runtimes"]["customer"]["archive"]["sha256"],
            )
            self.assertEqual(
                one["runtimes"]["driver"]["archive"]["sha256"],
                two["runtimes"]["driver"]["archive"]["sha256"],
            )


if __name__ == "__main__":
    unittest.main()
