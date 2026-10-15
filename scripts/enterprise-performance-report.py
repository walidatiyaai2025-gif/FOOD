#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

SHA_RE = re.compile(r"^[0-9a-f]{40,64}$")
PROFILES = {"smoke", "standard", "heavy", "cpanel"}
STATUSES = {"PASS", "FAIL", "WARN"}
DATASET_KEYS = (
    "orders",
    "order_items",
    "stock_movements",
    "finance_records",
    "audit_events_messages",
    "stores",
    "warehouses",
    "tenants",
)


def validate(report: dict[str, Any]) -> list[str]:
    errors: list[str] = []

    if report.get("schema_version") != 1:
        errors.append("schema_version must equal 1")

    for key in ("source_head", "harness_head"):
        value = report.get(key)
        if not isinstance(value, str) or SHA_RE.fullmatch(value) is None:
            errors.append(f"{key} must be a 40-64 character lowercase hex SHA")

    baseline_head = report.get("baseline_head")
    if baseline_head is not None and (
        not isinstance(baseline_head, str) or SHA_RE.fullmatch(baseline_head) is None
    ):
        errors.append("baseline_head must be null or a 40-64 character lowercase hex SHA")

    if report.get("profile") not in PROFILES:
        errors.append("profile must be smoke, standard, heavy, or cpanel")

    environment = report.get("environment")
    if not isinstance(environment, dict):
        errors.append("environment must be an object")
    else:
        for key in ("php", "database", "runner"):
            if not environment.get(key):
                errors.append(f"environment.{key} is required")

    dataset = report.get("dataset")
    if not isinstance(dataset, dict):
        errors.append("dataset must be an object")
    else:
        for key in DATASET_KEYS:
            value = dataset.get(key)
            if not isinstance(value, int) or value < 0:
                errors.append(f"dataset.{key} must be a non-negative integer")

    scenarios = report.get("scenarios")
    if not isinstance(scenarios, list) or not scenarios:
        errors.append("scenarios must be a non-empty array")
    else:
        for index, scenario in enumerate(scenarios):
            prefix = f"scenarios[{index}]"
            if not isinstance(scenario, dict):
                errors.append(f"{prefix} must be an object")
                continue
            if not scenario.get("id"):
                errors.append(f"{prefix}.id is required")
            rounds = scenario.get("rounds")
            if not isinstance(rounds, int) or rounds < 1:
                errors.append(f"{prefix}.rounds must be >= 1")
            metrics = scenario.get("metrics")
            if not isinstance(metrics, dict):
                errors.append(f"{prefix}.metrics must be an object")
            else:
                for key in ("p50_ms", "p95_ms", "p99_ms", "median_round_p95_ms"):
                    value = metrics.get(key)
                    if not isinstance(value, (int, float)) or value < 0:
                        errors.append(f"{prefix}.metrics.{key} must be a non-negative number")
            queries = scenario.get("queries")
            if not isinstance(queries, dict) or not isinstance(queries.get("count"), int):
                errors.append(f"{prefix}.queries.count must be an integer")
            memory = scenario.get("memory")
            if not isinstance(memory, dict) or not isinstance(memory.get("peak_mib"), (int, float)):
                errors.append(f"{prefix}.memory.peak_mib must be numeric")
            verdict = scenario.get("verdict")
            if not isinstance(verdict, dict) or verdict.get("status") not in STATUSES:
                errors.append(f"{prefix}.verdict.status must be PASS, FAIL, or WARN")

    summary = report.get("summary")
    if not isinstance(summary, dict) or summary.get("status") not in STATUSES:
        errors.append("summary.status must be PASS, FAIL, or WARN")

    return errors


def render_markdown(report: dict[str, Any]) -> str:
    errors = validate(report)
    if errors:
        raise ValueError("Invalid performance evidence:\n- " + "\n- ".join(errors))

    dataset = report["dataset"]
    lines = [
        "# FOODEX Enterprise Performance Evidence",
        "",
        f"- Status: **{report['summary']['status']}**",
        f"- Profile: `{report['profile']}`",
        f"- Source head: `{report['source_head']}`",
        f"- Harness head: `{report['harness_head']}`",
        f"- Baseline head: `{report.get('baseline_head') or 'none'}`",
        f"- Generated: {report.get('generated_at', 'unknown')}",
        "",
        "## Environment",
        "",
        f"- PHP: {report['environment']['php']}",
        f"- Database: {report['environment']['database']}",
        f"- Runner: {report['environment']['runner']}",
        f"- cPanel reference: {'yes' if report['environment'].get('cpanel_reference') else 'no'}",
        "",
        "## Dataset",
        "",
        "| Metric | Count |",
        "| --- | ---: |",
    ]
    lines.extend(f"| {key} | {dataset[key]} |" for key in DATASET_KEYS)
    lines.extend([
        "",
        "## Scenario results",
        "",
        "| Scenario | Class | p50 ms | p95 ms | p99 ms | Median round p95 ms | Queries | Peak MiB | Verdict |",
        "| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |",
    ])

    for scenario in report["scenarios"]:
        metrics = scenario["metrics"]
        lines.append(
            "| {id} | {klass} | {p50:.2f} | {p95:.2f} | {p99:.2f} | {median:.2f} | {queries} | {memory:.2f} | {status} |".format(
                id=scenario["id"],
                klass=scenario["workload_class"],
                p50=metrics["p50_ms"],
                p95=metrics["p95_ms"],
                p99=metrics["p99_ms"],
                median=metrics["median_round_p95_ms"],
                queries=scenario["queries"]["count"],
                memory=scenario["memory"]["peak_mib"],
                status=scenario["verdict"]["status"],
            )
        )

    slow_rows = []
    for scenario in report["scenarios"]:
        for query in scenario["queries"].get("slowest", []):
            slow_rows.append((scenario["id"], query))

    if slow_rows:
        lines.extend([
            "",
            "## Slowest queries",
            "",
            "| Scenario | ms | Count | Fingerprint | SQL |",
            "| --- | ---: | ---: | --- | --- |",
        ])
        for scenario_id, query in slow_rows:
            sql = str(query.get("sql", "")).replace("|", "\\|")
            lines.append(
                f"| {scenario_id} | {float(query.get('max_ms', 0)):.2f} | {int(query.get('count', 0))} | "
                f"`{query.get('fingerprint', '')}` | `{sql}` |"
            )

    warnings = report["summary"].get("warnings", [])
    if warnings:
        lines.extend(["", "## Warnings", ""])
        lines.extend(f"- {warning}" for warning in warnings)

    failed = report["summary"].get("failed_scenarios", [])
    if failed:
        lines.extend(["", "## Failed scenarios", ""])
        lines.extend(f"- {scenario_id}" for scenario_id in failed)

    return "\n".join(lines) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("input", type=Path)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--validate-only", action="store_true")
    args = parser.parse_args()

    report = json.loads(args.input.read_text(encoding="utf-8"))
    errors = validate(report)
    if errors:
        for error in errors:
            print(f"ERROR: {error}")
        return 1

    if args.validate_only:
        print("FOODEX Enterprise performance evidence PASS")
        return 0

    markdown = render_markdown(report)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(markdown, encoding="utf-8")
    else:
        print(markdown, end="")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
