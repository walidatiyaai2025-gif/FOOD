#!/usr/bin/env python3
"""Validate and query the active FOODEX autonomous Mission registry."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_REGISTRY = ROOT / "docs/execution/ACTIVE_FOOD_MISSION.json"
BRANCH_RE = re.compile(
    r"^(?P<prefix>feat|fix|chore|docs|refactor|test|ci)/(?P<issue>[0-9]+)-[a-z0-9-]+$"
)


def validate_registry(data: dict) -> list[str]:
    errors: list[str] = []

    if data.get("schema_version") != 1:
        errors.append("schema_version must be 1")
    if data.get("active") is not True:
        errors.append("active mission registry must set active=true")

    umbrella = data.get("umbrella_issue")
    if not isinstance(umbrella, int) or umbrella <= 0:
        errors.append("umbrella_issue must be a positive integer")

    target = data.get("integration_target")
    if not isinstance(target, str) or not target:
        errors.append("integration_target must be a non-empty branch name")
    if data.get("main_merge_allowed") is not False:
        errors.append("main_merge_allowed must be false for this mission")

    max_lanes = data.get("max_active_lanes")
    if not isinstance(max_lanes, int) or not 1 <= max_lanes <= 6:
        errors.append("max_active_lanes must be between 1 and 6")

    children = data.get("children")
    if not isinstance(children, list) or not children:
        errors.append("children must be a non-empty list")
        return errors

    seen_issues: set[int] = set()
    seen_branches: set[str] = set()
    dependencies: dict[int, list[int]] = {}

    for index, child in enumerate(children):
        if not isinstance(child, dict):
            errors.append(f"children[{index}] must be an object")
            continue

        issue = child.get("issue")
        branch = child.get("branch")
        depends = child.get("depends_on", [])

        if not isinstance(issue, int) or issue <= 0:
            errors.append(f"children[{index}].issue must be a positive integer")
            continue
        if issue == umbrella:
            errors.append(f"child #{issue} cannot equal umbrella_issue")
        if issue in seen_issues:
            errors.append(f"duplicate child issue #{issue}")
        seen_issues.add(issue)

        if not isinstance(branch, str):
            errors.append(f"child #{issue} branch must be a string")
        else:
            match = BRANCH_RE.fullmatch(branch)
            if not match:
                errors.append(f"child #{issue} has invalid canonical branch: {branch}")
            elif int(match.group("issue")) != issue:
                errors.append(
                    f"child #{issue} canonical branch embeds issue #{match.group('issue')}: {branch}"
                )
            if branch in seen_branches:
                errors.append(f"duplicate canonical branch: {branch}")
            seen_branches.add(branch)

        if not isinstance(depends, list) or any(not isinstance(dep, int) for dep in depends):
            errors.append(f"child #{issue} depends_on must be an integer list")
            dependencies[issue] = []
        else:
            dependencies[issue] = list(depends)
            if issue in depends:
                errors.append(f"child #{issue} cannot depend on itself")

    for issue, deps in dependencies.items():
        for dep in deps:
            if dep not in seen_issues:
                errors.append(f"child #{issue} depends on unknown mission child #{dep}")

    # Detect dependency cycles.
    visiting: set[int] = set()
    visited: set[int] = set()

    def visit(issue: int, path: list[int]) -> None:
        if issue in visited:
            return
        if issue in visiting:
            cycle = path[path.index(issue):] + [issue] if issue in path else path + [issue]
            errors.append("dependency cycle: " + " -> ".join(f"#{item}" for item in cycle))
            return
        visiting.add(issue)
        path.append(issue)
        for dep in dependencies.get(issue, []):
            if dep in seen_issues:
                visit(dep, path)
        path.pop()
        visiting.remove(issue)
        visited.add(issue)

    for issue in sorted(seen_issues):
        visit(issue, [])

    final_gate = data.get("final_gate_issue")
    if final_gate not in seen_issues:
        errors.append("final_gate_issue must reference a mission child")

    commands = data.get("owner_commands")
    if not isinstance(commands, list) or not commands or any(not isinstance(x, str) or not x for x in commands):
        errors.append("owner_commands must be a non-empty string list")

    return errors


def load_registry(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise SystemExit(f"Mission registry missing: {path}") from exc
    except json.JSONDecodeError as exc:
        raise SystemExit(f"Invalid mission registry JSON: {exc}") from exc


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--registry", type=Path, default=DEFAULT_REGISTRY)
    sub = parser.add_subparsers(dest="command")

    sub.add_parser("validate")

    branch_parser = sub.add_parser("branch")
    branch_parser.add_argument("issue", type=int)

    args = parser.parse_args()
    command = args.command or "validate"
    data = load_registry(args.registry)

    errors = validate_registry(data)
    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1

    if command == "validate":
        print(
            f"Active FOOD mission registry valid: {data['mission_id']} "
            f"umbrella=#{data['umbrella_issue']} children={len(data['children'])} "
            f"max_active_lanes={data['max_active_lanes']}"
        )
        return 0

    if command == "branch":
        child = next((x for x in data["children"] if x["issue"] == args.issue), None)
        if child is None:
            print(f"Issue #{args.issue} is not a child of active mission #{data['umbrella_issue']}", file=sys.stderr)
            return 2
        print(child["branch"])
        return 0

    parser.error(f"unknown command: {command}")
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
