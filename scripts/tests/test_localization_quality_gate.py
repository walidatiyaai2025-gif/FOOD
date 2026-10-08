from __future__ import annotations

import importlib.util
import tempfile
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

    def test_inline_bilingual_resolver_is_allowed(self):
        errors = []
        module.scan_added_lines(
            [("apps/van_app/lib/features/orders/orders_page.dart", 10, "Text(_text('Open orders', 'الطلبات المفتوحة')),")],
            errors,
        )
        self.assertEqual([], errors)

    def test_translation_usage_is_not_rejected_as_dynamic_enum(self):
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
        self.assertTrue(any("enum/status" in error for error in errors))

    def test_direct_payment_method_render_is_rejected(self):
        errors = []
        module.scan_added_lines(
            [("apps/customer_app/lib/features/orders/page.dart", 31, "Text(order.paymentMethod),")],
            errors,
        )
        self.assertTrue(any("payment" in error for error in errors))

    def test_direct_language_specific_name_is_rejected(self):
        errors = []
        module.scan_added_lines(
            [("apps/customer_app/lib/features/products/page.dart", 44, "Text(product.nameEn),")],
            errors,
        )
        self.assertTrue(any("language-specific" in error for error in errors))

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

    def test_explicit_technical_exception_marker_is_allowed(self):
        errors = []
        module.scan_added_lines(
            [("apps/driver_app/lib/features/debug/page.dart", 10, "Text('Protocol HTTP 200'), // localization-gate: allow technical protocol")],
            errors,
        )
        self.assertEqual([], errors)


    def test_blade_javascript_comparison_is_not_user_facing_text(self):
        original_root = module.ROOT
        try:
            with tempfile.TemporaryDirectory() as temp_dir:
                module.ROOT = Path(temp_dir)
                path = Path(temp_dir) / "backend/resources/views/admin/example.blade.php"
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(
                    "<div>{{ __('orders.title') }}</div>\n"
                    "<script>\n"
                    "const fits = inwardLeft >= margin && inwardLeft + menuRect.width <= window.innerWidth - margin;\n"
                    "</script>\n",
                    encoding="utf-8",
                )
                errors = []
                module.scan_added_lines(
                    [(
                        "backend/resources/views/admin/example.blade.php",
                        3,
                        "const fits = inwardLeft >= margin && inwardLeft + menuRect.width <= window.innerWidth - margin;",
                    )],
                    errors,
                )
                self.assertEqual([], errors)
        finally:
            module.ROOT = original_root

    def test_blade_style_content_is_not_user_facing_text(self):
        original_root = module.ROOT
        try:
            with tempfile.TemporaryDirectory() as temp_dir:
                module.ROOT = Path(temp_dir)
                path = Path(temp_dir) / "backend/resources/views/admin/example.blade.php"
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(
                    "<style>\n"
                    ".grid > .cell { width: calc(100% - 8px); }\n"
                    "</style>\n",
                    encoding="utf-8",
                )
                errors = []
                module.scan_added_lines(
                    [("backend/resources/views/admin/example.blade.php", 2, ".grid > .cell { width: calc(100% - 8px); }")],
                    errors,
                )
                self.assertEqual([], errors)
        finally:
            module.ROOT = original_root


    def test_blade_control_flow_fragments_are_not_visible_copy(self):
        errors = []
        module.scan_added_lines(
            [
                (
                    "backend/resources/views/admin/catalog-management.blade.php",
                    320,
                    "@foreach($categories->where('id','!=',$c->id) as $parent)<option>{{ $parent->name }}</option>@endforeach",
                ),
                (
                    "backend/resources/views/admin/customer-360-show.blade.php",
                    253,
                    "@if($orders->isEmpty())<div>{{ __('customer_360.records.no_orders') }}</div>@endif",
                ),
            ],
            errors,
        )
        self.assertEqual([], errors)


if __name__ == "__main__":
    unittest.main()
