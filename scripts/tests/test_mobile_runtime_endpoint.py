import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "verify-mobile-runtime-endpoint.py"
SPEC = importlib.util.spec_from_file_location("verify_mobile_runtime_endpoint", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class MobileRuntimeEndpointVerifierTest(unittest.TestCase):
    def _apk(self, root: Path, payloads: dict[str, bytes]) -> Path:
        apk = root / "app-release.apk"
        with zipfile.ZipFile(apk, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            for name, data in payloads.items():
                archive.writestr(name, data)
        return apk

    def test_accepts_approved_host(self):
        with tempfile.TemporaryDirectory() as directory:
            apk = self._apk(
                Path(directory),
                {"lib/arm64-v8a/libapp.so": b"prefix https://foodex.50sols.com suffix"},
            )
            result = MODULE.verify_apk(apk)
            self.assertEqual(["foodex.50sols.com"], result["approved_host"])
            self.assertEqual([], result["forbidden_locations"])

    def test_rejects_obsolete_van_host_even_when_approved_host_is_present(self):
        with tempfile.TemporaryDirectory() as directory:
            apk = self._apk(
                Path(directory),
                {
                    "lib/arm64-v8a/libapp.so": (
                        b"https://foodex.50sols.com "
                        b"https://vanfoodex.50sols.com"
                    )
                },
            )
            with self.assertRaisesRegex(ValueError, "Forbidden production host"):
                MODULE.verify_apk(apk)

    def test_rejects_apk_without_approved_host(self):
        with tempfile.TemporaryDirectory() as directory:
            apk = self._apk(
                Path(directory),
                {"lib/arm64-v8a/libapp.so": b"https://example.invalid"},
            )
            with self.assertRaisesRegex(ValueError, "Approved production host"):
                MODULE.verify_apk(apk)

    def test_scans_across_chunk_boundary(self):
        with tempfile.TemporaryDirectory() as directory:
            prefix = b"x" * (1024 * 1024 - 5)
            apk = self._apk(
                Path(directory),
                {"lib/arm64-v8a/libapp.so": prefix + b"foodex.50sols.com"},
            )
            MODULE.verify_apk(apk)


if __name__ == "__main__":
    unittest.main()
