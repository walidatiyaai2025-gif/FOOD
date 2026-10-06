from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "localization-quality-gate.py"
spec = importlib.util.spec_from_file_location("localization_quality_gate", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)


class LocalizationQualityGateTest(unittest.TestCase):
    def test_technical_literals_are_allowed(self):
        self.assertTrue(module.looks_like_technical_literal("FOODEX"))
        self.assertTrue(module.looks_like_technical_literal("API"))
        self.assertTrue(module.looks_like_technical_literal("/api/v1/orders"))
        self.assertTrue(module.looks_like_technical_literal("customer.orders.title"))

    def test_human_facing_literal_is_not_technical(self):
        self.assertFalse(module.looks_like_technical_literal("Open order"))
        self.assertFalse(module.looks_like_technical_literal("فتح الطلب"))

    def test_dart_raw_text_is_rejected(self):
        errors = []
        module.scan_added_lines(
            [("apps/driver_app/lib/features/orders/orders_page.dart", 10, "Text('Open order'),")],
            errors,
        )
        self.assertTrue(any("raw user-facing literal" in error for error in errors))

    def test_dart_translation_usage_is_not_rejected_as_dynamic_enum(self):
        errors = []
        module.scan_added_lines(
            [("apps/driver_app/lib/features/orders/orders_page.dart", 10, "Text(context.tr(order.statusKey)),")],
            errors,
        )
        self.assertEqual([], errors)

    def test_direct_status_render_is_rejected(self):
        errors = []
        module.scan_added_lines(
            [("apps/van_app/lib/features/orders/orders_page.dart", 25, "Text(order.status),")],
            errors,
        )
        self.assertTrue(any("enum/status-like data" in error for error in errors))

    def test_blade_raw_text_is_rejected(self):
        errors = []
        module.scan_added_lines(
            [("backend/resources/views/admin/orders.blade.php", 7, "<button>Open order</button>")],
            errors,
        )
        self.assertTrue(any("raw user-facing Blade text" in error for error in errors))

    def test_blade_translation_helper_is_allowed(self):
        errors = []
        module.scan_added_lines(
            [("backend/resources/views/admin/orders.blade.php", 7, "<button>{{ __('orders.open') }}</button>")],
            errors,
        )
        self.assertEqual([], errors)


if __name__ == "__main__":
    unittest.main()
