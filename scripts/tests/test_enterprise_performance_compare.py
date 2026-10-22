from __future__ import annotations

import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "enterprise-performance-compare.py"
SPEC = importlib.util.spec_from_file_location("enterprise_performance_compare", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def report(
    *,
    source: str,
    baseline: str | None,
    p95: float = 100.0,
    p99: float = 120.0,
    queries: int = 10,
    peak_bytes: int = 50_000_000,
    cpanel: bool = False,
    workload_class: str = "lightweight_authenticated_read",
) -> dict:
    return {
        "schema_version": 1,
        "generated_at": "2026-10-15T09:39:00Z",
        "source_head": source,
        "harness_head": "c" * 40,
        "baseline_head": baseline,
        "profile": "cpanel" if cpanel else "standard",
        "environment": {
            "php": "8.3.0",
            "database": "mysql 8",
            "runner": "reference" if cpanel else "github-actions",
            "memory_limit": "128M",
            "cpanel_reference": cpanel,
        },
        "dataset": {
            "orders": 100000,
            "order_items": 500000,
            "stock_movements": 250000,
            "finance_records": 100000,
            "audit_events_messages": 250000,
            "stores": 6,
            "warehouses": 12,
            "tenants": 3,
        },
        "scenarios": [
            {
                "id": "scenario.one",
                "workload_class": workload_class,
                "rounds": 3,
                "metrics": {
                    "p50_ms": p95 * 0.6,
                    "p95_ms": p95,
                    "p99_ms": p99,
                    "median_round_p95_ms": p95,
                },
                "queries": {"count": queries, "slowest": []},
                "memory": {
                    "peak_bytes": peak_bytes,
                    "peak_mib": peak_bytes / (1024 * 1024),
                },
                "baseline": None,
                "queue": None,
                "verdict": {"status": "PASS", "reasons": [], "exception": None},
            }
        ],
        "summary": {"status": "PASS", "failed_scenarios": [], "warnings": []},
    }


class EnterprisePerformanceCompareTest(unittest.TestCase):
    def test_threshold_edges_pass_but_values_above_fail(self) -> None:
        baseline = report(source="a" * 40, baseline=None)
        edge = report(
            source="b" * 40,
            baseline="a" * 40,
            p95=115.0,
            p99=144.0,
            queries=11,
            peak_bytes=57_500_000,
        )

        self.assertEqual("PASS", MODULE.compare_reports(baseline, edge)["status"])

        failing = copy.deepcopy(edge)
        failing["scenarios"][0]["metrics"]["median_round_p95_ms"] = 115.01
        failing["scenarios"][0]["metrics"]["p99_ms"] = 144.01
        failing["scenarios"][0]["queries"]["count"] = 12
        failing["scenarios"][0]["memory"]["peak_bytes"] = 57_500_001

        result = MODULE.compare_reports(baseline, failing)

        self.assertEqual("FAIL", result["status"])
        checks = {item["name"]: item for item in result["scenarios"][0]["checks"]}
        self.assertEqual("FAIL", checks["p95"]["status"])
        self.assertEqual("FAIL", checks["p99"]["status"])
        self.assertEqual("FAIL", checks["query_count"]["status"])
        self.assertEqual("FAIL", checks["memory"]["status"])

    def test_approved_exception_can_only_suppress_supported_relative_gates(self) -> None:
        baseline = report(source="a" * 40, baseline=None)
        candidate = report(
            source="b" * 40,
            baseline="a" * 40,
            p95=120.0,
            p99=150.0,
            queries=20,
            peak_bytes=70_000_000,
        )
        candidate["scenarios"][0]["verdict"]["exception"] = {
            "approved": True,
            "approved_by": "Performance Captain",
            "reason": "Temporary measured compatibility waiver",
            "review_by": "2026-11-01",
            "metrics": ["p95", "query_count", "memory"],
        }

        result = MODULE.compare_reports(baseline, candidate)
        checks = {item["name"]: item for item in result["scenarios"][0]["checks"]}

        self.assertEqual("PASS", checks["p95"]["status"])
        self.assertTrue(checks["p95"]["exception_applied"])
        self.assertEqual("PASS", checks["query_count"]["status"])
        self.assertEqual("PASS", checks["memory"]["status"])
        self.assertEqual("FAIL", checks["p99"]["status"])
        self.assertEqual("FAIL", result["status"])

    def test_cpanel_guardrail_uses_median_round_p95_and_has_no_exception(self) -> None:
        baseline = report(
            source="a" * 40,
            baseline=None,
            p95=900.0,
            p99=950.0,
            cpanel=True,
        )
        candidate = report(
            source="b" * 40,
            baseline="a" * 40,
            p95=1000.01,
            p99=1000.0,
            cpanel=True,
        )
        candidate["scenarios"][0]["verdict"]["exception"] = {
            "approved": True,
            "approved_by": "Performance Captain",
            "reason": "Relative waiver only",
            "review_by": "2026-11-01",
            "metrics": ["p95"],
        }

        result = MODULE.compare_reports(baseline, candidate)
        checks = {item["name"]: item for item in result["scenarios"][0]["checks"]}

        self.assertEqual("FAIL", checks["cpanel_absolute_p95"]["status"])
        self.assertFalse(checks["cpanel_absolute_p95"]["exception_applied"])
        self.assertEqual("FAIL", result["status"])

    def test_environment_or_scenario_mismatch_fails_closed(self) -> None:
        baseline = report(source="a" * 40, baseline=None)
        candidate = report(source="b" * 40, baseline="a" * 40)
        candidate["environment"]["database"] = "mariadb 11"
        candidate["scenarios"][0]["id"] = "new.scenario"

        result = MODULE.compare_reports(baseline, candidate)

        self.assertEqual("FAIL", result["status"])
        self.assertTrue(any("environment fingerprint mismatch" in item for item in result["failures"]))
        self.assertTrue(any("missing baseline scenario" in item for item in result["failures"]))
        self.assertTrue(any("no accepted baseline" in item for item in result["failures"]))

    def test_github_php_patch_drift_is_equivalent_but_minor_drift_fails(self) -> None:
        baseline = report(source="a" * 40, baseline=None)
        baseline["environment"]["php"] = "8.3.35"
        candidate = report(source="b" * 40, baseline="a" * 40)
        candidate["environment"]["php"] = "8.3.36"

        self.assertEqual("PASS", MODULE.compare_reports(baseline, candidate)["status"])

        candidate["environment"]["php"] = "8.4.0"
        result = MODULE.compare_reports(baseline, candidate)

        self.assertEqual("FAIL", result["status"])
        self.assertTrue(any("environment fingerprint mismatch" in item for item in result["failures"]))

    def test_github_smoke_ignores_small_absolute_latency_jitter_only(self) -> None:
        baseline = report(source="a" * 40, baseline=None, p95=10.0, p99=12.0)
        baseline["profile"] = "smoke"
        candidate = report(
            source="b" * 40,
            baseline="a" * 40,
            p95=12.0,
            p99=15.0,
        )
        candidate["profile"] = "smoke"

        result = MODULE.compare_reports(baseline, candidate)
        self.assertEqual("PASS", result["status"])

        candidate["scenarios"][0]["metrics"]["median_round_p95_ms"] = 22.0
        candidate["scenarios"][0]["metrics"]["p99_ms"] = 35.0
        result = MODULE.compare_reports(baseline, candidate)
        checks = {item["name"]: item for item in result["scenarios"][0]["checks"]}

        self.assertEqual("FAIL", result["status"])
        self.assertEqual("FAIL", checks["p95"]["status"])
        self.assertEqual("FAIL", checks["p99"]["status"])

    def test_cpanel_php_patch_drift_remains_strict(self) -> None:
        baseline = report(source="a" * 40, baseline=None, cpanel=True)
        baseline["environment"]["php"] = "8.3.35"
        candidate = report(source="b" * 40, baseline="a" * 40, cpanel=True)
        candidate["environment"]["php"] = "8.3.36"

        result = MODULE.compare_reports(baseline, candidate)

        self.assertEqual("FAIL", result["status"])
        self.assertTrue(any("environment fingerprint mismatch" in item for item in result["failures"]))

    def test_slow_query_threshold_is_reported_as_investigation_warning(self) -> None:
        baseline = report(source="a" * 40, baseline=None)
        candidate = report(source="b" * 40, baseline="a" * 40)
        candidate["scenarios"][0]["queries"]["slowest"] = [
            {
                "fingerprint": "f" * 64,
                "sql": "select * from orders where id = ?",
                "count": 1,
                "max_ms": 250.0,
            }
        ]

        result = MODULE.compare_reports(baseline, candidate)

        self.assertEqual("PASS", result["status"])
        self.assertEqual(1, len(result["warnings"]))
        self.assertIn(">=250ms", result["warnings"][0])

    def test_baseline_storage_is_versioned_and_immutable(self) -> None:
        baseline = report(source="a" * 40, baseline=None)

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            first = MODULE.accept_baseline(baseline, root)
            second = MODULE.accept_baseline(baseline, root)

            self.assertEqual(first, second)
            self.assertEqual(baseline, json.loads(first.read_text(encoding="utf-8")))

            changed = copy.deepcopy(baseline)
            changed["scenarios"][0]["metrics"]["p50_ms"] = 99.0

            with self.assertRaises(FileExistsError):
                MODULE.accept_baseline(changed, root)


if __name__ == "__main__":
    unittest.main()
