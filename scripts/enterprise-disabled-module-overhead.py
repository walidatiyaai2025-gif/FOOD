#!/usr/bin/env python3
from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path
from typing import Any

SCRIPT_DIR = Path(__file__).resolve().parent
COMPARE_PATH = SCRIPT_DIR / "enterprise-performance-compare.py"
SPEC = importlib.util.spec_from_file_location("enterprise_performance_compare", COMPARE_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load Enterprise performance comparator.")
COMPARE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(COMPARE)

ZERO_WORK_FIELDS = (
    "module_specific_global_queries",
    "module_specific_boot_work_count",
    "recurring_work_count",
    "provider_calls",
)


def evaluate_disabled_module(
    baseline: dict[str, Any],
    candidate: dict[str, Any],
) -> dict[str, Any]:
    comparison = COMPARE.compare_reports(baseline, candidate)
    failures = list(comparison["failures"])
    checks: list[dict[str, Any]] = []

    module_off = candidate.get("module_off")
    if not isinstance(module_off, dict):
        failures.append("candidate is missing module_off evidence")
        module_off = {}

    state = module_off.get("state")
    state_check = {
        "name": "module_state",
        "expected": "DISABLED",
        "candidate": state,
        "status": "PASS" if state == "DISABLED" else "FAIL",
    }
    checks.append(state_check)
    if state_check["status"] == "FAIL":
        failures.append("module_off.state must equal DISABLED")

    module_keys = module_off.get("module_keys")
    module_keys_ok = (
        isinstance(module_keys, list)
        and len(module_keys) > 0
        and all(isinstance(item, str) and item.strip() for item in module_keys)
    )
    checks.append(
        {
            "name": "module_keys",
            "candidate": module_keys,
            "status": "PASS" if module_keys_ok else "FAIL",
        }
    )
    if not module_keys_ok:
        failures.append("module_off.module_keys must contain at least one module key")

    for field in ZERO_WORK_FIELDS:
        value = module_off.get(field)
        passed = isinstance(value, int) and value == 0
        checks.append(
            {
                "name": field,
                "expected": 0,
                "candidate": value,
                "status": "PASS" if passed else "FAIL",
            }
        )
        if not passed:
            failures.append(f"module_off.{field} must equal 0 while disabled")

    return {
        "schema_version": 1,
        "status": "FAIL" if failures else "PASS",
        "baseline_source_head": comparison["baseline_source_head"],
        "candidate_source_head": comparison["candidate_source_head"],
        "candidate_harness_head": comparison["candidate_harness_head"],
        "profile": comparison["profile"],
        "environment_key": comparison["environment_key"],
        "relative_performance": comparison,
        "module_off_checks": checks,
        "failures": failures,
        "warnings": comparison["warnings"],
    }


def _load(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("baseline", type=Path)
    parser.add_argument("candidate", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    result = evaluate_disabled_module(_load(args.baseline), _load(args.candidate))
    rendered = json.dumps(result, indent=2, sort_keys=True) + "\n"

    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered, encoding="utf-8")
    else:
        print(rendered, end="")

    return 0 if result["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
