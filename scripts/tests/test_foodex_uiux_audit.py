from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "foodex-uiux-audit.py"
spec = importlib.util.spec_from_file_location("foodex_uiux_audit", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class FoodexUiuxAuditTest(unittest.TestCase):
    def test_dashboard_hard_coded_hex_is_rejected(self):
        rules = [{
            "id": "hex",
            "severity": "error",
            "deduction": 20,
            "scope_regex": r"^backend/resources/views/admin/.*\.blade\.php$",
            "added_regex": r"#[0-9A-Fa-f]{6}\b",
            "message": "no hex",
        }]
        findings = module.scan_added(
            "backend/resources/views/admin/new.blade.php",
            ".card{background:#123456}",
            rules,
        )
        self.assertEqual("hex", findings[0].rule)

    def test_shared_brand_file_can_be_excluded(self):
        rules = [{
            "id": "hex",
            "severity": "error",
            "deduction": 20,
            "scope_regex": r"^backend/resources/views/admin/.*\.blade\.php$",
            "added_regex": r"#[0-9A-Fa-f]{6}\b",
            "exclude_paths": ["backend/resources/views/admin/_brand.blade.php"],
            "message": "no hex",
        }]
        self.assertEqual(
            [],
            module.scan_added(
                "backend/resources/views/admin/_brand.blade.php",
                "--foodex-new:#123456;",
                rules,
            ),
        )

    def test_parallel_mobile_navigation_is_rejected(self):
        rules = [{
            "id": "nav",
            "severity": "error",
            "deduction": 30,
            "scope_regex": r"^apps/(customer|driver|van)_app/lib/features/.*\.dart$",
            "added_regex": r"\b(?:BottomNavigationBar|NavigationBar)\s*\(",
            "message": "no parallel nav",
        }]
        findings = module.scan_added(
            "apps/van_app/lib/features/orders/new_page.dart",
            "return NavigationBar(destinations: const []);",
            rules,
        )
        self.assertEqual("nav", findings[0].rule)

    def test_raw_visible_id_input_is_rejected(self):
        rules = [{
            "id": "raw-id",
            "severity": "error",
            "deduction": 25,
            "scope_regex": r"^backend/resources/views/admin/.*\.blade\.php$",
            "added_regex": r"<input\b(?=[^>]*(?:name|id)=[\"'][^\"']*_id[\"'])(?=[^>]*type=[\"'](?:text|number)[\"'])[^>]*>",
            "message": "lookup required",
        }]
        findings = module.scan_added(
            "backend/resources/views/admin/new.blade.php",
            '<input name="driver_id" type="number">',
            rules,
        )
        self.assertEqual("raw-id", findings[0].rule)

    def test_policy_files_validate_against_minimal_repo(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            policy = root / ".ai/uiux"
            policy.mkdir(parents=True)

            source = root / "source.txt"
            golden = root / "golden.txt"
            source.write_text("x", encoding="utf-8")
            golden.write_text("x", encoding="utf-8")

            (policy / "component-registry.json").write_text(json.dumps({
                "surfaces": {"dashboard": {"canonical_sources": ["source.txt"]}}
            }), encoding="utf-8")
            (policy / "page-archetypes.json").write_text(json.dumps({
                "archetypes": {"management-list": {}}
            }), encoding="utf-8")
            (policy / "golden-pages.json").write_text(json.dumps({
                "surfaces": {"dashboard": {"management-list": ["golden.txt"]}}
            }), encoding="utf-8")
            (policy / "uiux-scorecard.json").write_text(json.dumps({
                "threshold": 90,
                "categories": [{"id": "all", "weight": 100}]
            }), encoding="utf-8")
            (policy / "forbidden-patterns.json").write_text(json.dumps({
                "rules": [{
                    "id": "x",
                    "scope_regex": ".*",
                    "added_regex": "bad",
                    "message": "bad",
                }]
            }), encoding="utf-8")

            self.assertEqual([], module.validate_policy(root))


if __name__ == "__main__":
    unittest.main()
