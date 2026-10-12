#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from typing import Iterable

EXPECTED_COUNTS = {
    "products": 39,
    "product_images": 39,
    "product_selling_units": 36,
    "categories": 17,
    "stores": 3,
    "store_products": 39,
    "orders": 82,
    "order_items": 150,
    "payments": 93,
    "translations": 566,
}

SENSITIVE_TABLES = {
    "users",
    "customers",
    "addresses",
    "audit_logs",
    "password_reset_tokens",
    "personal_access_tokens",
    "push_tokens",
    "device_tokens",
    "provider_credentials",
    "settings",
}

_INSERT_RE = re.compile(
    r"INSERT\s+INTO\s+`([^`]+)`\s+\(([^)]+)\)\s+VALUES\s*(.*);\s*$",
    re.IGNORECASE | re.DOTALL,
)


def split_sql_statements(text: str) -> Iterable[str]:
    start = 0
    in_single = False
    in_double = False
    escaped = False

    for index, char in enumerate(text):
        if escaped:
            escaped = False
            continue

        if char == "\\" and (in_single or in_double):
            escaped = True
            continue

        if char == "'" and not in_double:
            in_single = not in_single
            continue

        if char == '"' and not in_single:
            in_double = not in_double
            continue

        if char == ";" and not in_single and not in_double:
            yield text[start : index + 1]
            start = index + 1

    if start < len(text):
        yield text[start:]


def count_value_tuples(values: str) -> int:
    count = 0
    depth = 0
    in_single = False
    in_double = False
    escaped = False

    for char in values:
        if escaped:
            escaped = False
            continue

        if char == "\\" and (in_single or in_double):
            escaped = True
            continue

        if char == "'" and not in_double:
            in_single = not in_single
            continue

        if char == '"' and not in_single:
            in_double = not in_double
            continue

        if in_single or in_double:
            continue

        if char == "(":
            if depth == 0:
                count += 1
            depth += 1
        elif char == ")":
            depth = max(0, depth - 1)

    return count


def inspect_dump(raw: bytes) -> dict[str, object]:
    text = raw.decode("utf-8", errors="replace")
    counts: dict[str, int] = {}
    inserted_tables: set[str] = set()

    for statement in split_sql_statements(text):
        match = _INSERT_RE.search(statement.strip())
        if match is None:
            continue

        table = match.group(1)
        inserted_tables.add(table)
        counts[table] = counts.get(table, 0) + count_value_tuples(match.group(3))

    observed = {table: counts.get(table, 0) for table in EXPECTED_COUNTS}
    mismatches = {
        table: {"expected": expected, "observed": observed[table]}
        for table, expected in EXPECTED_COUNTS.items()
        if observed[table] != expected
    }

    return {
        "schema_version": 1,
        "source_sha256": hashlib.sha256(raw).hexdigest(),
        "source_size_bytes": len(raw),
        "expected_counts": EXPECTED_COUNTS,
        "observed_counts": observed,
        "count_mismatches": mismatches,
        "sensitive_tables_present": sorted(inserted_tables & SENSITIVE_TABLES),
        "eligible_for_sanitized_fixture_derivation": not mismatches,
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Verify a private FOODEX SQL dump matches the documented Customer "
            "VNext baseline without exporting raw rows or PII."
        )
    )
    parser.add_argument("dump", type=Path)
    parser.add_argument(
        "--output",
        type=Path,
        help="Optional JSON manifest path. The manifest contains counts/fingerprint only.",
    )
    args = parser.parse_args()

    raw = args.dump.read_bytes()
    report = inspect_dump(raw)
    payload = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"

    if args.output is not None:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(payload, encoding="utf-8")
    else:
        print(payload, end="")

    return 0 if report["eligible_for_sanitized_fixture_derivation"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
