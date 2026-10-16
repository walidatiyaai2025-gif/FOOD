from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("mobile_ci_scope", ROOT / "scripts/mobile-ci-scope.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class MobileCiScopeTest(unittest.TestCase):
    def test_plain_dart_change_builds_android_without_expensive_ios(self) -> None:
        plan = MODULE.resolve("customer", ["apps/customer_app/lib/app.dart"])
        self.assertTrue(plan["android"])
        self.assertFalse(plan["ios"])
        self.assertFalse(plan["web_preview"])

    def test_test_only_change_skips_platform_builds(self) -> None:
        plan = MODULE.resolve("customer", ["apps/customer_app/test/auth_test.dart"])
        self.assertFalse(plan["android"])
        self.assertFalse(plan["ios"])
        self.assertFalse(plan["web_preview"])

    def test_pubspec_change_enables_all_supported_platforms(self) -> None:
        plan = MODULE.resolve("driver", ["apps/driver_app/pubspec.yaml"])
        self.assertTrue(plan["android"])
        self.assertTrue(plan["ios"])
        self.assertTrue(plan["web_preview"])
        self.assertTrue(plan["store"])

    def test_preview_change_builds_preview_and_android(self) -> None:
        plan = MODULE.resolve("customer", ["apps/customer_app/lib/core/preview/shell.dart"])
        self.assertTrue(plan["android"])
        self.assertTrue(plan["web_preview"])
        self.assertFalse(plan["ios"])

    def test_van_never_schedules_ios(self) -> None:
        plan = MODULE.resolve("van", ["apps/van_app/pubspec.yaml"])
        self.assertTrue(plan["android"])
        self.assertFalse(plan["ios"])

    def test_backend_dashboard_only_changes_never_schedule_platform_builds(self) -> None:
        paths = [
            "backend/app/Services/OrderService.php",
            "backend/resources/views/admin/orders/index.blade.php",
        ]
        for app in ("customer", "driver", "van"):
            plan = MODULE.resolve(app, paths)
            self.assertFalse(plan["android"], app)
            self.assertFalse(plan["ios"], app)
            self.assertFalse(plan["web_preview"], app)


if __name__ == "__main__":
    unittest.main()
