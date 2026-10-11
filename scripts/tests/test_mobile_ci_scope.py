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


if __name__ == "__main__":
    unittest.main()
