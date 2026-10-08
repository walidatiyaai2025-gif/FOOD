from __future__ import annotations

import copy
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "scripts"
sys.path.insert(0, str(SCRIPTS))

import validate_ui_route_convergence as convergence  # noqa: E402


class UiRouteConvergenceRegistryTest(unittest.TestCase):
    def setUp(self) -> None:
        self.registry = json.loads(
            (ROOT / "docs/execution/UI_ROUTE_AUTHORITY.json").read_text(
                encoding="utf-8"
            )
        )

    def test_current_tree_is_converged(self) -> None:
        self.assertEqual([], convergence.validate(ROOT, self.registry))

    def test_duplicate_function_ids_are_rejected(self) -> None:
        registry = copy.deepcopy(self.registry)
        duplicate = copy.deepcopy(
            registry["surfaces"]["customer"]["functions"][0]
        )
        registry["surfaces"]["driver"]["functions"].append(duplicate)

        errors = convergence.validate(ROOT, registry)

        self.assertTrue(
            any("Duplicate UI function ids" in error for error in errors),
            errors,
        )

    def test_missing_authority_source_is_rejected(self) -> None:
        registry = copy.deepcopy(self.registry)
        registry["surfaces"]["customer"]["authority_source"] = (
            "apps/customer_app/lib/core/routing/missing.dart"
        )

        errors = convergence.validate(ROOT, registry)

        self.assertTrue(
            any("customer.authority_source is missing" in error for error in errors),
            errors,
        )


if __name__ == "__main__":
    unittest.main()
