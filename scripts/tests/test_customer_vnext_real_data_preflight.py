from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "customer_vnext_real_data_preflight",
    ROOT / "scripts/customer-vnext-real-data-preflight.py",
)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)


class CustomerVNextRealDataPreflightTest(unittest.TestCase):
    def test_statement_splitter_ignores_semicolons_inside_strings(self):
        sql = (
            "INSERT INTO `products` (`id`, `name`) VALUES "
            "(1, 'alpha;beta'), (2, 'gamma');"
            "INSERT INTO `orders` (`id`) VALUES (1);"
        )
        statements = list(module.split_sql_statements(sql))
        self.assertEqual(2, len(statements))

    def test_tuple_counter_ignores_parentheses_inside_strings(self):
        values = "(1, 'Pack (12)'), (2, 'normal'), (3, 'x,y')"
        self.assertEqual(3, module.count_value_tuples(values))

    def test_report_exposes_only_counts_fingerprint_and_sensitive_table_names(self):
        inserts = []
        for table, count in module.EXPECTED_COUNTS.items():
            rows = ", ".join(f"({index})" for index in range(1, count + 1))
            inserts.append(f"INSERT INTO `{table}` (`id`) VALUES {rows};")
        inserts.append(
            "INSERT INTO `users` (`id`, `email`) "
            "VALUES (1, 'real-person@example.invalid');"
        )

        report = module.inspect_dump("".join(inserts).encode())

        self.assertTrue(report["eligible_for_sanitized_fixture_derivation"])
        self.assertEqual(["users"], report["sensitive_tables_present"])
        self.assertNotIn("real-person@example.invalid", str(report))

    def test_stale_snapshot_is_rejected_when_any_baseline_count_differs(self):
        report = module.inspect_dump(
            b"INSERT INTO `products` (`id`) VALUES (1);"
        )

        self.assertFalse(report["eligible_for_sanitized_fixture_derivation"])
        self.assertEqual(
            {"expected": 39, "observed": 1},
            report["count_mismatches"]["products"],
        )


if __name__ == "__main__":
    unittest.main()
