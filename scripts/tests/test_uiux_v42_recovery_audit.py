from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "uiux-v42-recovery-audit.py"
spec = importlib.util.spec_from_file_location("uiux_v42_recovery_audit", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class UiuxV42RecoveryAuditTest(unittest.TestCase):
    def test_visible_json_and_numeric_id_inputs_are_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            views = root / "backend/resources/views/admin"
            views.mkdir(parents=True)
            (views / "bad.blade.php").write_text(
                '<input type="number" name="store_id">\n'
                '<textarea name="rules_json"></textarea>\n',
                encoding="utf-8",
            )
            findings = module.scan_admin_raw_inputs(root)
            self.assertTrue(any("store_id" in finding for finding in findings))
            self.assertTrue(any("rules_json" in finding for finding in findings))

    def test_text_internal_id_input_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            views = root / "backend/resources/views/admin"
            views.mkdir(parents=True)
            (views / "bad-id.blade.php").write_text(
                '<input type="text" name="user_id">\n'
                '<input type="hidden" name="store_id">\n'
                '<select name="driver_id"><option value="1">Driver One</option></select>\n',
                encoding="utf-8",
            )
            findings = module.scan_admin_raw_inputs(root)
            self.assertTrue(any("user_id" in finding for finding in findings))
            self.assertFalse(any("store_id" in finding for finding in findings))
            self.assertFalse(any("driver_id" in finding for finding in findings))

    def test_hidden_transport_and_privileged_advanced_json_are_allowed(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            views = root / "backend/resources/views/admin"
            views.mkdir(parents=True)
            (views / "ok.blade.php").write_text(
                '<input type="hidden" name="rules_json">\n'
                '@if($isSuper)\n'
                '<details data-advanced-routing-json>\n'
                '<textarea name="rules_json"></textarea>\n'
                '</details>\n'
                '@endif\n'
                '<textarea name="credentials_json"></textarea>\n',
                encoding="utf-8",
            )
            self.assertEqual([], module.scan_admin_raw_inputs(root))

    def test_direct_dynamic_enum_rendering_is_rejected_but_localized_rendering_passes(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            views = root / "backend/resources/views/admin"
            views.mkdir(parents=True)
            (views / "status.blade.php").write_text(
                '{{ $order->status }}\n'
                "{{ __('orders.statuses.'.$order->status) }}\n",
                encoding="utf-8",
            )
            findings = module.scan_admin_raw_inputs(root)
            self.assertEqual(1, len([item for item in findings if "without localization" in item]))

    def test_mobile_direct_status_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            page = root / "apps/driver_app/lib/features/orders/page.dart"
            page.parent.mkdir(parents=True)
            page.write_text(
                "Text(order.status),\nText(context.tr(order.statusKey)),\n",
                encoding="utf-8",
            )
            for app in ("customer", "van"):
                (root / f"apps/{app}_app/lib").mkdir(parents=True)
            findings = module.scan_mobile_dynamic_enums(root)
            self.assertEqual(1, len(findings))

    def test_required_dashboard_contract_guards_are_verified(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            old_guards = module.REQUIRED_BACKEND_CONTRACT_GUARDS
            try:
                module.REQUIRED_BACKEND_CONTRACT_GUARDS = {
                    "backend/tests/Feature/GuardTest.php": ("test_required_contract",),
                }
                path = root / "backend/tests/Feature/GuardTest.php"
                path.parent.mkdir(parents=True)
                path.write_text("function test_required_contract() {}", encoding="utf-8")
                self.assertEqual([], module.validate_backend_contract_guards(root))
                path.write_text("function something_else() {}", encoding="utf-8")
                errors = module.validate_backend_contract_guards(root)
                self.assertTrue(any("test_required_contract" in error for error in errors))
            finally:
                module.REQUIRED_BACKEND_CONTRACT_GUARDS = old_guards

    def test_requirement_matrix_and_json_must_match_owners(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            req = root / "docs/execution/UIUX_V42_RECOVERY_REQUIREMENTS.json"
            req.parent.mkdir(parents=True)
            req.write_text(
                json.dumps(
                    {
                        "mission_id": "UIUX-V42-RECOVERY",
                        "requirements": [
                            {"id": "D01", "owner_issue": 1035, "evidence": "source+test", "status": "OPEN"}
                        ],
                    }
                ),
                encoding="utf-8",
            )
            matrix = root / "docs/execution/UIUX_V42_RECOVERY_REQUIREMENT_MATRIX.md"
            matrix.write_text(
                "| D01 | Sidebar | #1036 | source + test | OPEN |\n",
                encoding="utf-8",
            )
            old_evidence = module.IMPLEMENTATION_EVIDENCE
            old_inventory = module.LEGACY_INVENTORY
            try:
                module.IMPLEMENTATION_EVIDENCE = {}
                module.LEGACY_INVENTORY = ()
                errors, _ = module.validate_requirement_coverage(root)
            finally:
                module.IMPLEMENTATION_EVIDENCE = old_evidence
                module.LEGACY_INVENTORY = old_inventory
            self.assertTrue(any("owner mismatch" in error for error in errors))


if __name__ == "__main__":
    unittest.main()
