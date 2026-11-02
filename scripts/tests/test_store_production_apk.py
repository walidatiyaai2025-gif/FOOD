"""Production Android origin verification is isolated from demo distribution."""
import tempfile
import unittest
import zipfile
from pathlib import Path
import importlib.util

SOURCE = Path(__file__).resolve().parents[1] / "verify-store-production-apk.py"
SPEC = importlib.util.spec_from_file_location("foodex_store_production_verifier", SOURCE)
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class ProductionApkTests(unittest.TestCase):
    def make_apk(self, payload: bytes) -> Path:
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        apk = Path(tmp.name) / "release.apk"
        with zipfile.ZipFile(apk, "w") as archive:
            archive.writestr("lib/arm64-v8a/libapp.so", payload)
        return apk

    def test_accepts_production_origin(self):
        result = module.verify(self.make_apk(b"api=https://store.foodexeg.com/api/v1"))
        self.assertEqual(result["api_base_url"], "https://store.foodexeg.com")

    def test_rejects_demo_origin(self):
        with self.assertRaisesRegex(ValueError, "Old/demo"):
            module.verify(self.make_apk(b"https://store.foodexeg.com https://foodex.50sols.com"))

    def test_rejects_missing_production_origin(self):
        with self.assertRaisesRegex(ValueError, "not embedded"):
            module.verify(self.make_apk(b"https://elsewhere.example.test"))

    def test_rejects_invalid_apk(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        apk = Path(tmp.name) / "broken.apk"
        apk.write_bytes(b"invalid")
        with self.assertRaises(ValueError):
            module.verify(apk)


if __name__ == "__main__":
    unittest.main()
