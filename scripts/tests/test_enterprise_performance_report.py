from __future__ import annotations

import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "enterprise-performance-report.py"
SPEC = importlib.util.spec_from_file_location("enterprise_performance_report", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def valid_report() -> dict:
    sha = "a" * 40
    return {
        "schema_version": 1,
        "generated_at": "2026-10-15T09:39:00Z",
        "source_head": sha,
        "harness_head": "b" * 40,
        "baseline_head": sha,
        "profile": "standard",
        "environment": {
            "php": "8.3.0",
            "database": "mysql 8",
            "runner": "github-actions",
            "memory_limit": "128M",
            "cpanel_reference": False,
        },
        "dataset": {
            "orders": 100000,
            "order_items": 500000,
            "stock_movements": 250000,
            "finance_records": 100000,
            "audit_events_messages": 250000,
            "stores": 8,
            "warehouses": 12,
            "tenants": 3,
        },
        "scenarios": [
            {
                "id": "orders.index",
                "workload_class": "paginated_crud",
                "rounds": 3,
                "metrics": {
                    "p50_ms": 120.0,
                    "p95_ms": 180.0,
                    "p99_ms": 210.0,
                    "median_round_p95_ms": 175.0,
                },
                "queries": {
                    "count": 8,
                    "slowest": [
                        {
                            "fingerprint": "f" * 64,
                            "sql": "select * from orders where store_id = ?",
                            "count": 3,
                            "max_ms": 260.0,
                        }
                    ],
                },
                "memory": {"peak_bytes": 67108864, "peak_mib": 64.0},
                "baseline": None,
                "queue": None,
                "verdict": {"status": "PASS", "reasons": [], "exception": None},
            }
        ],
        "summary": {"status": "PASS", "failed_scenarios": [], "warnings": []},
    }


class EnterprisePerformanceReportTest(unittest.TestCase):
    def test_valid_report_passes_and_renders_numeric_evidence(self) -> None:
        report = valid_report()

        self.assertEqual([], MODULE.validate(report))

        markdown = MODULE.render_markdown(report)
        self.assertIn("orders.index", markdown)
        self.assertIn("180.00", markdown)
        self.assertIn("Queries | Peak MiB", markdown)
        self.assertIn("260.00", markdown)
        self.assertIn(report["source_head"], markdown)

    def test_missing_exact_head_and_metrics_fail_closed(self) -> None:
        report = valid_report()
        report["source_head"] = "main"
        del report["scenarios"][0]["metrics"]["p99_ms"]

        errors = MODULE.validate(report)

        self.assertTrue(any("source_head" in error for error in errors))
        self.assertTrue(any("p99_ms" in error for error in errors))

    def test_empty_scenarios_are_not_valid_evidence(self) -> None:
        report = valid_report()
        report["scenarios"] = []

        errors = MODULE.validate(report)

        self.assertIn("scenarios must be a non-empty array", errors)


if __name__ == "__main__":
    unittest.main()
