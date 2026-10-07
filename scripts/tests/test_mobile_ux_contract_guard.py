from __future__ import annotations

import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "mobile-ux-contract-guard.py"
spec = importlib.util.spec_from_file_location("mobile_ux_contract_guard", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules[spec.name] = module
spec.loader.exec_module(module)


def findings(source: str, app: str = "customer"):
    path = f"apps/{app}_app/lib/features/example/example_screen.dart"
    hunk = module.hunk_from_source(path, source)
    return module.validate_hunk(app, hunk)


class MobileUxContractGuardTest(unittest.TestCase):
    def test_tri_app_roots_are_mandatory(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for app in ("customer", "driver"):
                (root / f"apps/{app}_app/lib").mkdir(parents=True)
            contract = root / "docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md"
            contract.parent.mkdir(parents=True)
            contract.write_text(
                "\n".join(module.REQUIRED_CONTRACT_TOKENS),
                encoding="utf-8",
            )
            errors = module.validate_repository_contract(root)
            self.assertTrue(any("missing app roots: van" in error for error in errors))

    def test_contract_tokens_are_mandatory(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for rel in module.APP_ROOTS.values():
                (root / rel).mkdir(parents=True)
            contract = root / "docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md"
            contract.parent.mkdir(parents=True)
            contract.write_text("## incomplete\n", encoding="utf-8")
            errors = module.validate_repository_contract(root)
            self.assertTrue(any("14.1 Compact title/header" in error for error in errors))
            self.assertTrue(any("14.6 Row actions" in error for error in errors))

    def test_oversized_toolbar_is_rejected(self):
        result = findings(
            """
Widget build(BuildContext context) {
  return Scaffold(
    body: AppBar(toolbarHeight: 96),
  );
}
"""
        )
        self.assertTrue(any(item.rule == "compact-header" for item in result))

    def test_narrow_centered_screen_shell_is_rejected(self):
        result = findings(
            """
Widget build(BuildContext context) {
  return Scaffold(
    body: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: const Placeholder(),
      ),
    ),
  );
}
"""
        )
        self.assertTrue(any(item.rule == "full-width" for item in result))

    def test_stacked_start_end_filters_are_rejected(self):
        result = findings(
            """
Widget buildFilters() {
  return Column(
    children: [
      TextField(controller: startDate),
      TextField(controller: endDate),
    ],
  );
}
"""
        )
        self.assertTrue(any(item.rule == "one-line-filters" for item in result))

    def test_same_line_start_end_filters_pass(self):
        result = findings(
            """
Widget buildFilters() {
  return Row(
    children: [
      Expanded(child: TextField(controller: startDate)),
      Expanded(child: TextField(controller: endDate)),
    ],
  );
}
"""
        )
        self.assertFalse(any(item.rule == "one-line-filters" for item in result))

    def test_order_identifier_without_nowrap_is_rejected(self):
        result = findings(
            """
Widget buildOrder(CustomerOrder order) {
  return Text('#${order.id}');
}
"""
        )
        self.assertTrue(any(item.rule == "order-nowrap" for item in result))

    def test_order_identifier_with_nowrap_passes(self):
        result = findings(
            """
Widget buildOrder(CustomerOrder order) {
  return Text(
    '#${order.id}',
    maxLines: 1,
    overflow: TextOverflow.ellipsis,
  );
}
"""
        )
        self.assertFalse(any(item.rule == "order-nowrap" for item in result))

    def test_multi_button_mobile_row_without_ellipsis_is_rejected(self):
        result = findings(
            """
Widget buildList() {
  return ListView.builder(
    itemBuilder: (context, index) => ListTile(
      trailing: Row(
        children: [
          TextButton(onPressed: () {}, child: const Text('View')),
          FilledButton(onPressed: () {}, child: const Text('Edit')),
        ],
      ),
    ),
  );
}
"""
        )
        self.assertTrue(any(item.rule == "ellipsis-actions" for item in result))

    def test_ellipsis_mobile_row_passes(self):
        result = findings(
            """
Widget buildList() {
  return ListView.builder(
    itemBuilder: (context, index) => ListTile(
      trailing: PopupMenuButton<String>(
        icon: const Icon(Icons.more_vert),
        itemBuilder: (context) => const [],
      ),
    ),
  );
}
"""
        )
        self.assertFalse(any(item.rule == "ellipsis-actions" for item in result))

    def test_same_rules_apply_to_all_three_apps(self):
        bad = """
Widget buildOrder(dynamic order) {
  return Text('${order.reference}');
}
"""
        for app in ("customer", "driver", "van"):
            with self.subTest(app=app):
                self.assertTrue(
                    any(item.rule == "order-nowrap" for item in findings(bad, app))
                )


if __name__ == "__main__":
    unittest.main()
