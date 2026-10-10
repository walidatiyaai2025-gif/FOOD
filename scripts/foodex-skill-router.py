#!/usr/bin/env python3
"""Resolve the minimum FOODEX repository-native skill pack for a git diff.

The router is deterministic and machine-readable. It does not prove that an
agent read a skill; it makes the required skill/gate contract explicit and
CI-verifiable for the exact PR diff.
"""
from __future__ import annotations

import argparse
import fnmatch
import json
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parents[1]
ROUTER_PATH = ROOT / ".ai" / "skill-router.json"


@dataclass(frozen=True)
class ChangedFile:
    status: str
    path: str
    added_text: str = ""


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(["git", "-C", str(root), *args], text=True)


def load_router(root: Path = ROOT) -> dict:
    path = root / ".ai" / "skill-router.json"
    return json.loads(path.read_text(encoding="utf-8"))


def matches(path: str, patterns: Iterable[str]) -> bool:
    return any(fnmatch.fnmatchcase(path, pattern) for pattern in patterns)


def validate_router(config: dict, root: Path = ROOT) -> list[str]:
    errors: list[str] = []

    if config.get("schema_version") != 1:
        errors.append("skill-router: schema_version must be 1")

    skill_root = root / str(config.get("skill_root", ".ai/skills"))
    if not skill_root.is_dir():
        errors.append(f"skill-router: skill_root missing: {skill_root.relative_to(root)}")

    route_ids: set[str] = set()
    referenced_skills: set[str] = set()

    routes = config.get("routes")
    if not isinstance(routes, list) or not routes:
        errors.append("skill-router: routes must be a non-empty list")
        routes = []

    for route in routes:
        route_id = str(route.get("id", "")).strip()
        if not route_id:
            errors.append("skill-router: route without id")
            continue
        if route_id in route_ids:
            errors.append(f"skill-router: duplicate route id: {route_id}")
        route_ids.add(route_id)

        patterns = route.get("paths")
        if not isinstance(patterns, list) or not patterns:
            errors.append(f"skill-router: route {route_id} has no paths")

        skills = route.get("skills", [])
        if not isinstance(skills, list) or not skills:
            errors.append(f"skill-router: route {route_id} has no skills")
        for skill in skills:
            referenced_skills.add(str(skill))

    condition_ids: set[str] = set()
    for rule in config.get("conditional_rules", []):
        rule_id = str(rule.get("id", "")).strip()
        if not rule_id:
            errors.append("skill-router: conditional rule without id")
            continue
        if rule_id in condition_ids:
            errors.append(f"skill-router: duplicate conditional rule id: {rule_id}")
        condition_ids.add(rule_id)

        for route_id in rule.get("route_ids", []):
            if route_id not in route_ids:
                errors.append(
                    f"skill-router: conditional {rule_id} references unknown route: {route_id}"
                )
        try:
            re.compile(str(rule.get("added_regex", "")))
        except re.error as exc:
            errors.append(f"skill-router: conditional {rule_id} has invalid regex: {exc}")

        for skill in rule.get("skills", []):
            referenced_skills.add(str(skill))

    for skill in sorted(referenced_skills):
        if not (skill_root / skill).is_file():
            errors.append(f"skill-router: referenced skill missing: {skill}")

    return errors


def diff_rows(base: str, head: str, root: Path = ROOT) -> list[ChangedFile]:
    output = git(root, "diff", "--name-status", "--find-renames", base, head)
    rows: list[ChangedFile] = []

    for raw in output.splitlines():
        parts = raw.split("\t")
        if not parts:
            continue

        status = parts[0][:1]
        if parts[0].startswith("R") and len(parts) >= 3:
            path = parts[2]
            status = "R"
        elif len(parts) >= 2:
            path = parts[1]
        else:
            continue

        added = "" if status == "D" else added_text(base, head, path, root)
        rows.append(ChangedFile(status=status, path=path, added_text=added))

    return rows


def added_text(base: str, head: str, path: str, root: Path = ROOT) -> str:
    output = git(root, "diff", "--unified=0", base, head, "--", path)
    lines: list[str] = []
    for line in output.splitlines():
        if line.startswith("+++") or not line.startswith("+"):
            continue
        lines.append(line[1:])
    return "\n".join(lines)


def resolve(config: dict, changed: Iterable[ChangedFile]) -> dict:
    files = list(changed)
    selected_routes: dict[str, set[str]] = {}
    skills: set[str] = set()
    gates: set[str] = set()
    conditions: set[str] = set()
    unmatched_controlled: list[str] = []

    controlled_roots = tuple(str(x) for x in config.get("controlled_roots", []))
    routes = config.get("routes", [])

    for item in files:
        matched_route_ids: set[str] = set()

        for route in routes:
            if matches(item.path, route.get("paths", [])):
                route_id = str(route["id"])
                matched_route_ids.add(route_id)
                selected_routes.setdefault(route_id, set()).add(item.path)
                skills.update(str(x) for x in route.get("skills", []))
                gates.update(str(x) for x in route.get("gates", []))

        if controlled_roots and item.path.startswith(controlled_roots) and not matched_route_ids:
            unmatched_controlled.append(item.path)

        if not matched_route_ids or not item.added_text:
            continue

        for rule in config.get("conditional_rules", []):
            applicable_routes = set(str(x) for x in rule.get("route_ids", []))
            if not (matched_route_ids & applicable_routes):
                continue
            if re.search(str(rule.get("added_regex", "")), item.added_text):
                conditions.add(str(rule["id"]))
                skills.update(str(x) for x in rule.get("skills", []))
                gates.update(str(x) for x in rule.get("gates", []))

    return {
        "schema_version": 1,
        "changed_files": [
            {"status": item.status, "path": item.path}
            for item in files
        ],
        "routes": {
            route_id: sorted(paths)
            for route_id, paths in sorted(selected_routes.items())
        },
        "conditional_rules": sorted(conditions),
        "skills": sorted(skills),
        "gates": sorted(gates),
        "unmatched_controlled_paths": sorted(set(unmatched_controlled)),
        "ok": not unmatched_controlled,
    }


def write_github_summary(report: dict) -> None:
    summary_path = Path(str(__import__("os").environ.get("GITHUB_STEP_SUMMARY", "")))
    if not summary_path:
        return

    lines = [
        "## FOODEX Skill Router",
        "",
        f"- Routes: {', '.join(report['routes']) if report['routes'] else 'none'}",
        f"- Conditional rules: {', '.join(report['conditional_rules']) if report['conditional_rules'] else 'none'}",
        f"- Skills: {', '.join(report['skills']) if report['skills'] else 'none'}",
        f"- Gates: {', '.join(report['gates']) if report['gates'] else 'none'}",
    ]
    summary_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base")
    parser.add_argument("--head")
    parser.add_argument("--report")
    parser.add_argument("--validate-only", action="store_true")
    parser.add_argument("--strict", action="store_true")
    parser.add_argument("--github-summary", action="store_true")
    args = parser.parse_args()

    config = load_router(ROOT)
    errors = validate_router(config, ROOT)

    if args.validate_only:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        if errors:
            return 1
        print("FOODEX skill router config PASS")
        return 0

    if not args.base or not args.head:
        parser.error("--base and --head are required unless --validate-only is used")

    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1

    report = resolve(config, diff_rows(args.base, args.head, ROOT))
    report["base"] = args.base
    report["head"] = args.head
    report["router_errors"] = []

    if args.report:
        target = ROOT / args.report
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(
            json.dumps(report, indent=2, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )

    if args.github_summary:
        write_github_summary(report)

    print("FOODEX skill routes:", ", ".join(report["routes"]) or "none")
    print("FOODEX required skills:", ", ".join(report["skills"]) or "none")
    print("FOODEX required gates:", ", ".join(report["gates"]) or "none")

    if report["unmatched_controlled_paths"]:
        for path in report["unmatched_controlled_paths"]:
            print(f"UNMATCHED CONTROLLED PATH: {path}", file=sys.stderr)
        if args.strict:
            return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
