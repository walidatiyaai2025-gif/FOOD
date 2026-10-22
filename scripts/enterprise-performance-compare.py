#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
from typing import Any

SCRIPT_DIR = Path(__file__).resolve().parent
REPORT_PATH = SCRIPT_DIR / "enterprise-performance-report.py"
SPEC = importlib.util.spec_from_file_location("enterprise_performance_report", REPORT_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load Enterprise performance evidence validator.")
REPORT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(REPORT)

RELATIVE_THRESHOLDS = {
    "p95": 15.0,
    "p99": 20.0,
    "query_count": 15.0,
    "memory": 15.0,
}

CPANEL_P95_GUARDRAILS_MS = {
    "lightweight_authenticated_read": 1000.0,
    "paginated_crud": 1500.0,
    "transactional_write": 1800.0,
    "dashboard": 2000.0,
    "bounded_interactive_report": 2500.0,
    "provider_independent_order_commit": 1800.0,
}


def canonical_environment_key(report: dict[str, Any]) -> str:
    environment = report["environment"]
    identity = {
        "php": environment.get("php"),
        "database": environment.get("database"),
        "runner": environment.get("runner"),
        "memory_limit": environment.get("memory_limit"),
        "cpanel_reference": bool(environment.get("cpanel_reference")),
    }
    payload = json.dumps(identity, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()[:16]


def comparison_environment_key(report: dict[str, Any]) -> str:
    """Fingerprint environments for an apples-to-apples comparison.

    GitHub's setup-php can roll an 8.3.x patch forward even when the workflow
    requests an older patch. Patch-only drift on the same hosted runner is not
    a meaningful environment change for the smoke comparison; the measured
    latency/query/memory gates still detect regressions. Reference/cPanel
    evidence remains strict, as do PHP minor, database, runner and memory-limit
    changes.
    """
    environment = report["environment"]
    php = str(environment.get("php") or "")
    runner = environment.get("runner")
    cpanel_reference = bool(environment.get("cpanel_reference"))

    if runner == "github-actions" and not cpanel_reference:
        parts = php.split(".")
        if len(parts) >= 2 and all(part.isdigit() for part in parts[:2]):
            php = ".".join(parts[:2])

    identity = {
        "php": php,
        "database": environment.get("database"),
        "runner": runner,
        "memory_limit": environment.get("memory_limit"),
        "cpanel_reference": cpanel_reference,
    }
    payload = json.dumps(identity, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()[:16]


def _approved_exception(scenario: dict[str, Any], metric: str) -> dict[str, Any] | None:
    verdict = scenario.get("verdict")
    if not isinstance(verdict, dict):
        return None

    exception = verdict.get("exception")
    if not isinstance(exception, dict):
        return None

    metrics = exception.get("metrics")
    if not isinstance(metrics, list) or metric not in metrics:
        return None

    if exception.get("approved") is not True:
        return None

    for key in ("approved_by", "reason", "review_by"):
        value = exception.get(key)
        if not isinstance(value, str) or not value.strip():
            return None

    return exception


def _regression_percent(baseline: float, candidate: float) -> float | None:
    if baseline == 0:
        return 0.0 if candidate == 0 else None

    return ((candidate - baseline) / baseline) * 100.0


def _relative_check(
    *,
    name: str,
    baseline_value: float,
    candidate_value: float,
    threshold_percent: float,
    exception: dict[str, Any] | None = None,
) -> dict[str, Any]:
    regression = _regression_percent(baseline_value, candidate_value)
    exceeds = candidate_value > baseline_value and (
        regression is None or regression > threshold_percent
    )
    exception_applied = exceeds and exception is not None

    return {
        "name": name,
        "kind": "relative",
        "baseline": baseline_value,
        "candidate": candidate_value,
        "regression_percent": regression,
        "threshold_percent": threshold_percent,
        "status": "PASS" if not exceeds or exception_applied else "FAIL",
        "exception_applied": exception_applied,
        "exception": exception if exception_applied else None,
    }


def _absolute_p95_check(candidate: dict[str, Any]) -> dict[str, Any] | None:
    workload_class = candidate.get("workload_class")
    limit = CPANEL_P95_GUARDRAILS_MS.get(workload_class)
    if limit is None:
        return None

    p95 = float(candidate["metrics"]["median_round_p95_ms"])

    return {
        "name": "cpanel_absolute_p95",
        "kind": "absolute",
        "candidate": p95,
        "limit_ms": limit,
        "status": "PASS" if p95 <= limit else "FAIL",
        "exception_applied": False,
        "exception": None,
    }


def compare_reports(baseline: dict[str, Any], candidate: dict[str, Any]) -> dict[str, Any]:
    baseline_errors = REPORT.validate(baseline)
    candidate_errors = REPORT.validate(candidate)
    if baseline_errors or candidate_errors:
        errors = [f"baseline: {item}" for item in baseline_errors]
        errors.extend(f"candidate: {item}" for item in candidate_errors)
        raise ValueError("Invalid performance evidence:\n- " + "\n- ".join(errors))

    failures: list[str] = []
    warnings: list[str] = []

    if baseline["profile"] != candidate["profile"]:
        failures.append("profile mismatch between baseline and candidate")

    baseline_environment_key = comparison_environment_key(baseline)
    candidate_environment_key = comparison_environment_key(candidate)
    if baseline_environment_key != candidate_environment_key:
        failures.append("environment fingerprint mismatch between baseline and candidate")

    declared_baseline = candidate.get("baseline_head")
    if declared_baseline != baseline["source_head"]:
        failures.append(
            "candidate baseline_head does not match baseline source_head "
            f"({declared_baseline!r} != {baseline['source_head']!r})"
        )

    baseline_scenarios = {scenario["id"]: scenario for scenario in baseline["scenarios"]}
    candidate_scenarios = {scenario["id"]: scenario for scenario in candidate["scenarios"]}

    missing_candidate = sorted(set(baseline_scenarios) - set(candidate_scenarios))
    missing_baseline = sorted(set(candidate_scenarios) - set(baseline_scenarios))

    for scenario_id in missing_candidate:
        failures.append(f"candidate is missing baseline scenario: {scenario_id}")
    for scenario_id in missing_baseline:
        failures.append(f"candidate scenario has no accepted baseline: {scenario_id}")

    scenario_results: list[dict[str, Any]] = []
    is_cpanel_reference = bool(candidate["environment"].get("cpanel_reference"))

    for scenario_id in sorted(set(baseline_scenarios) & set(candidate_scenarios)):
        baseline_scenario = baseline_scenarios[scenario_id]
        candidate_scenario = candidate_scenarios[scenario_id]
        checks: list[dict[str, Any]] = []

        if baseline_scenario.get("workload_class") != candidate_scenario.get("workload_class"):
            checks.append(
                {
                    "name": "workload_class",
                    "kind": "identity",
                    "baseline": baseline_scenario.get("workload_class"),
                    "candidate": candidate_scenario.get("workload_class"),
                    "status": "FAIL",
                    "exception_applied": False,
                    "exception": None,
                }
            )

        checks.append(
            _relative_check(
                name="p95",
                baseline_value=float(baseline_scenario["metrics"]["median_round_p95_ms"]),
                candidate_value=float(candidate_scenario["metrics"]["median_round_p95_ms"]),
                threshold_percent=RELATIVE_THRESHOLDS["p95"],
                exception=_approved_exception(candidate_scenario, "p95"),
            )
        )
        checks.append(
            _relative_check(
                name="p99",
                baseline_value=float(baseline_scenario["metrics"]["p99_ms"]),
                candidate_value=float(candidate_scenario["metrics"]["p99_ms"]),
                threshold_percent=RELATIVE_THRESHOLDS["p99"],
            )
        )
        checks.append(
            _relative_check(
                name="query_count",
                baseline_value=float(baseline_scenario["queries"]["count"]),
                candidate_value=float(candidate_scenario["queries"]["count"]),
                threshold_percent=RELATIVE_THRESHOLDS["query_count"],
                exception=_approved_exception(candidate_scenario, "query_count"),
            )
        )
        checks.append(
            _relative_check(
                name="memory",
                baseline_value=float(baseline_scenario["memory"]["peak_bytes"]),
                candidate_value=float(candidate_scenario["memory"]["peak_bytes"]),
                threshold_percent=RELATIVE_THRESHOLDS["memory"],
                exception=_approved_exception(candidate_scenario, "memory"),
            )
        )

        if is_cpanel_reference:
            absolute = _absolute_p95_check(candidate_scenario)
            if absolute is not None:
                checks.append(absolute)

        slowest = candidate_scenario["queries"].get("slowest", [])
        critical_over_250 = [
            query for query in slowest if float(query.get("max_ms", 0.0)) >= 250.0
        ]
        if critical_over_250:
            warnings.append(
                f"{scenario_id}: {len(critical_over_250)} captured query fingerprint(s) "
                "reached the >=250ms investigation threshold"
            )

        scenario_failures = [check["name"] for check in checks if check["status"] == "FAIL"]
        status = "FAIL" if scenario_failures else "PASS"
        if status == "FAIL":
            failures.append(
                f"{scenario_id}: failed checks: {', '.join(scenario_failures)}"
            )

        scenario_results.append(
            {
                "id": scenario_id,
                "workload_class": candidate_scenario.get("workload_class"),
                "status": status,
                "checks": checks,
            }
        )

    return {
        "schema_version": 1,
        "status": "FAIL" if failures else "PASS",
        "baseline_source_head": baseline["source_head"],
        "candidate_source_head": candidate["source_head"],
        "candidate_harness_head": candidate["harness_head"],
        "profile": candidate["profile"],
        "environment_key": candidate_environment_key,
        "scenarios": scenario_results,
        "failures": failures,
        "warnings": warnings,
    }


def accept_baseline(report: dict[str, Any], root: Path) -> Path:
    errors = REPORT.validate(report)
    if errors:
        raise ValueError("Invalid performance evidence:\n- " + "\n- ".join(errors))

    environment_key = canonical_environment_key(report)
    destination = (
        root
        / environment_key
        / report["profile"]
        / f"{report['source_head']}.json"
    )
    payload = json.dumps(report, indent=2, sort_keys=True) + "\n"

    if destination.exists():
        existing = destination.read_text(encoding="utf-8")
        if existing == payload:
            return destination
        raise FileExistsError(
            f"Refusing to overwrite immutable performance baseline: {destination}"
        )

    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(payload, encoding="utf-8")

    return destination


def _load(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> int:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)

    compare_parser = subparsers.add_parser("compare")
    compare_parser.add_argument("baseline", type=Path)
    compare_parser.add_argument("candidate", type=Path)
    compare_parser.add_argument("--output", type=Path)

    accept_parser = subparsers.add_parser("accept")
    accept_parser.add_argument("evidence", type=Path)
    accept_parser.add_argument(
        "--root",
        type=Path,
        default=Path("benchmarks/enterprise/baselines"),
    )

    args = parser.parse_args()

    if args.command == "accept":
        path = accept_baseline(_load(args.evidence), args.root)
        print(path)
        return 0

    result = compare_reports(_load(args.baseline), _load(args.candidate))
    rendered = json.dumps(result, indent=2, sort_keys=True) + "\n"

    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered, encoding="utf-8")
    else:
        print(rendered, end="")

    return 0 if result["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
