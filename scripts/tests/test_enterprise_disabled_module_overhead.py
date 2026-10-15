from __future__ import annotations

import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "enterprise-disabled-module-overhead.py"
SPEC = importlib.util.spec_from_file_location("enterprise_disabled_module_overhead", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def report(source: str, baseline_head: str | None = None) -> dict:
    return {
        "schema_version": 1,
        "generated_at": "2026-10-15T10:30:00Z",
        "source_head": source,
        "harness_head": "c" * 40,
        "baseline_head": baseline_head,
        "profile": "smoke",
        "environment": {
            "php": "8.3.35",
            "database": "sqlite 3.45.1",
            "runner": "github-actions",
            "memory_limit": "-1",
            "cpanel_reference": False,
        },
        "dataset": {
            "orders": 200,
            "order_items": 1000,
            "stock_movements": 500,
            "finance_records": 200,
            "audit_events_messages": 500,
            "stores": 3,
            "warehouses": 6,
            "tenants": 3,
        },
        "scenarios": [
            {
                "id": "admin.dashboard",
                "workload_class": "dashboard",
                "rounds": 3,
                "metrics": {
                    "p50_ms": 10.0,
                    "p95_ms": 12.0,
                    "p99_ms": 13.0,
                    "median_round_p95_ms": 12.0,
                },
                "queries": {"count": 50, "slowest": []},
                "memory": {"peak_bytes": 1000000, "peak_mib": 0.9537},
                "baseline": None,
                "queue": None,
                "verdict": {"status": "PASS", "reasons": [], "exception": None},
            }
        ],
        "summary": {"status": "PASS", "failed_scenarios": [], "warnings": []},
    }


class DisabledModuleOverheadTest(unittest.TestCase):
    def candidate(self) -> tuple[dict, dict]:
        baseline = report("a" * 40)
        candidate = report("b" * 40, baseline_head=baseline["source_head"])
        candidate["module_off"] = {
            "state": "DISABLED",
            "module_keys": ["enterprise.sample"],
            "module_specific_global_queries": 0,
            "module_specific_boot_work_count": 0,
            "recurring_work_count": 0,
            "provider_calls": 0,
        }
        return baseline, candidate

    def test_disabled_module_with_zero_global_work_and_no_regression_passes(self) -> None:
        baseline, candidate = self.candidate()

        result = MODULE.evaluate_disabled_module(baseline, candidate)

        self.assertEqual("PASS", result["status"])
        self.assertEqual([], result["failures"])
        self.assertTrue(
            all(check["status"] == "PASS" for check in result["module_off_checks"])
        )

    def test_relative_regression_still_uses_shared_comparator_thresholds(self) -> None:
        baseline, candidate = self.candidate()
        candidate["scenarios"][0]["metrics"]["median_round_p95_ms"] = 14.0

        result = MODULE.evaluate_disabled_module(baseline, candidate)

        self.assertEqual("FAIL", result["status"])
        self.assertTrue(
            any("p95" in failure for failure in result["relative_performance"]["failures"])
        )

    def test_any_disabled_module_recurring_or_global_work_fails(self) -> None:
        baseline, candidate = self.candidate()
        candidate["module_off"]["module_specific_global_queries"] = 1
        candidate["module_off"]["recurring_work_count"] = 1

        result = MODULE.evaluate_disabled_module(baseline, candidate)

        self.assertEqual("FAIL", result["status"])
        self.assertIn(
            "module_off.module_specific_global_queries must equal 0 while disabled",
            result["failures"],
        )
        self.assertIn(
            "module_off.recurring_work_count must equal 0 while disabled",
            result["failures"],
        )

    def test_missing_off_evidence_fails_closed(self) -> None:
        baseline, candidate = self.candidate()
        del candidate["module_off"]

        result = MODULE.evaluate_disabled_module(baseline, candidate)

        self.assertEqual("FAIL", result["status"])
        self.assertIn("candidate is missing module_off evidence", result["failures"])


if __name__ == "__main__":
    unittest.main()
