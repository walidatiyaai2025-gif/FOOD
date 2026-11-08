from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

from verify_release_capabilities import (
    CapabilityGateError,
    REQUIRED_FILES,
    verify_update_archive,
)


class ReleaseCapabilityGateTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.zip_path = Path(self.temp.name) / "FOODEX-Update.zip"
        self.entries = {"VERSION": "1.0.126\n"}
        for path, markers in REQUIRED_FILES.items():
            self.entries[path] = "\n".join(markers) if markers else "protected runtime file\n"

    def tearDown(self) -> None:
        self.temp.cleanup()

    def package(self) -> None:
        with ZipFile(self.zip_path, "w") as archive:
            for path, text in sorted(self.entries.items()):
                archive.writestr(path, text)

    def test_complete_capabilities_pass(self) -> None:
        self.package()
        verify_update_archive(self.zip_path, "1.0.126")

    def test_missing_group_screen_fails(self) -> None:
        del self.entries["backend/resources/views/admin/customer-groups.blade.php"]
        self.package()
        with self.assertRaisesRegex(CapabilityGateError, "missing required capability file"):
            verify_update_archive(self.zip_path, "1.0.126")

    def test_version_relabel_fails(self) -> None:
        self.entries["VERSION"] = "1.0.125\n"
        self.package()
        with self.assertRaisesRegex(CapabilityGateError, "does not match target"):
            verify_update_archive(self.zip_path, "1.0.126")

    def test_missing_scoped_route_fails(self) -> None:
        path = "backend/routes/web.php"
        self.entries[path] = self.entries[path].replace("/b2c/customers/groups", "")
        self.package()
        with self.assertRaisesRegex(CapabilityGateError, "Capability marker"):
            verify_update_archive(self.zip_path, "1.0.126")

    def test_missing_discount_ui_marker_fails(self) -> None:
        path = "backend/resources/views/admin/order-operations.blade.php"
        self.entries[path] = self.entries[path].replace("customer_service_discount.label", "")
        self.package()
        with self.assertRaisesRegex(CapabilityGateError, "Capability marker"):
            verify_update_archive(self.zip_path, "1.0.126")


if __name__ == "__main__":
    unittest.main()
