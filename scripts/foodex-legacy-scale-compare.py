#!/usr/bin/env python3
"""Compare #2274 1x and 4x workload evidence and render a closure report."""

from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path


def load(path: str) -> dict:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("baseline")
    parser.add_argument("scaled")
    parser.add_argument("--json-output", required=True)
    parser.add_argument("--markdown-output", required=True)
    args = parser.parse_args()

    baseline = load(args.baseline)
    scaled = load(args.scaled)

    failures: list[str] = []
    if baseline.get("profile") != "legacy1x":
        failures.append("baseline profile must be legacy1x")
    if scaled.get("profile") != "legacy4x":
        failures.append("scaled profile must be legacy4x")
    if int(scaled.get("concurrency", 0)) < 50:
        failures.append("scaled workload concurrency is below 50")
    if int(scaled.get("field_users", 0)) < 25:
        failures.append("scaled field-user workload is below 25")
    if not scaled.get("pass"):
        failures.extend(str(item) for item in scaled.get("gate_errors", []))

    rows = []
    baseline_scenarios = baseline.get("scenarios", {})
    scaled_scenarios = scaled.get("scenarios", {})
    for scenario in sorted(set(baseline_scenarios) | set(scaled_scenarios)):
        before = baseline_scenarios.get(scenario, {})
        after = scaled_scenarios.get(scenario, {})
        before_p95 = float(before.get("p95_ms", 0.0))
        after_p95 = float(after.get("p95_ms", 0.0))
        ratio = after_p95 / before_p95 if before_p95 > 0 else None
        rows.append(
            {
                "scenario": scenario,
                "baseline_p95_ms": before_p95,
                "scaled_p95_ms": after_p95,
                "scaled_p99_ms": float(after.get("p99_ms", 0.0)),
                "scaled_error_rate": float(after.get("error_rate", 0.0)),
                "p95_ratio": ratio,
                "limit_p95_ms": float(after.get("limit_p95_ms", 0.0)),
                "pass": bool(after.get("pass", False)),
            }
        )

    report = {
        "schema_version": 1,
        "issue": 2274,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "baseline_profile": baseline.get("profile"),
        "scaled_profile": scaled.get("profile"),
        "concurrency": scaled.get("concurrency"),
        "field_users": scaled.get("field_users"),
        "office_users": scaled.get("office_users"),
        "baseline_rps": baseline.get("requests_per_second"),
        "scaled_rps": scaled.get("requests_per_second"),
        "baseline_error_rate": baseline.get("error_rate"),
        "scaled_error_rate": scaled.get("error_rate"),
        "scenarios": rows,
        "pass": not failures,
        "failures": failures,
    }

    json_path = Path(args.json_output)
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, indent=2, sort_keys=True), encoding="utf-8")

    md = [
        "# FOODEX #2274 Legacy 4× Performance Gate",
        "",
        f"Generated: {report['generated_at']}",
        "",
        f"Final result: **{'PASS' if report['pass'] else 'FAIL'}**",
        "",
        f"- Concurrent interactive users: {report['concurrency']}",
        f"- Active field/Driver users: {report['field_users']}",
        f"- Office users: {report['office_users']}",
        f"- Baseline requests/sec: {float(report['baseline_rps'] or 0):.2f}",
        f"- 4× requests/sec: {float(report['scaled_rps'] or 0):.2f}",
        f"- Baseline error rate: {float(report['baseline_error_rate'] or 0) * 100:.2f}%",
        f"- 4× error rate: {float(report['scaled_error_rate'] or 0) * 100:.2f}%",
        "",
        "| Scenario | 1× p95 ms | 4× p95 ms | 4× p99 ms | 4× limit ms | p95 ratio | Error % | Result |",
        "|---|---:|---:|---:|---:|---:|---:|---|",
    ]

    for row in rows:
        ratio = "n/a" if row["p95_ratio"] is None else f"{row['p95_ratio']:.2f}×"
        md.append(
            "| {scenario} | {baseline_p95_ms:.2f} | {scaled_p95_ms:.2f} | "
            "{scaled_p99_ms:.2f} | {limit_p95_ms:.2f} | {ratio} | "
            "{error:.2f}% | {result} |".format(
                scenario=row["scenario"],
                baseline_p95_ms=row["baseline_p95_ms"],
                scaled_p95_ms=row["scaled_p95_ms"],
                scaled_p99_ms=row["scaled_p99_ms"],
                limit_p95_ms=row["limit_p95_ms"],
                ratio=ratio,
                error=row["scaled_error_rate"] * 100,
                result="PASS" if row["pass"] else "FAIL",
            )
        )

    if failures:
        md.extend(["", "## Failures", ""])
        md.extend(f"- {failure}" for failure in failures)

    Path(args.markdown_output).write_text("\n".join(md) + "\n", encoding="utf-8")
    print("\n".join(md))
    return 0 if report["pass"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
