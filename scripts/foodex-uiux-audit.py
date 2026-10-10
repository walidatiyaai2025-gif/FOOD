#!/usr/bin/env python3
"""FOODEX changed-surface UI/UX policy gate.

This gate is deliberately conservative: it enforces machine-verifiable reuse
and anti-regression rules on the PR diff. It complements, and never replaces,
runtime screenshot/interaction evidence.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from dataclasses import dataclass, asdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY_ROOT = ROOT / ".ai" / "uiux"


@dataclass(frozen=True)
class Finding:
    rule: str
    severity: str
    path: str
    message: str
    deduction: int = 0
    excerpt: str | None = None


def load_json(name: str, root: Path = ROOT) -> dict:
    path = root / ".ai" / "uiux" / name
    return json.loads(path.read_text(encoding="utf-8"))


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(["git", "-C", str(root), *args], text=True)


def changed_files(base: str, head: str, root: Path = ROOT) -> list[tuple[str, str]]:
    output = git(root, "diff", "--name-status", "--find-renames", base, head)
    rows: list[tuple[str, str]] = []
    for raw in output.splitlines():
        parts = raw.split("\t")
        if not parts:
            continue
        status = parts[0]
        if status.startswith("R") and len(parts) >= 3:
            rows.append(("R", parts[2]))
        elif len(parts) >= 2:
            rows.append((status[:1], parts[1]))
    return rows


def added_text(base: str, head: str, path: str, root: Path = ROOT) -> str:
    output = git(root, "diff", "--unified=0", base, head, "--", path)
    lines = []
    for line in output.splitlines():
        if line.startswith("+++") or not line.startswith("+"):
            continue
        lines.append(line[1:])
    return "\n".join(lines)


def ui_surface(path: str) -> str | None:
    if path.startswith("backend/resources/views/admin/") and path.endswith(".blade.php"):
        return "dashboard"
    if path.startswith("backend/public/assets/admin/") and path.endswith((".css", ".js")):
        return "dashboard"
    for app in ("customer", "driver", "van"):
        if path.startswith(f"apps/{app}_app/lib/") and path.endswith(".dart"):
            return app
    if path.startswith("packages/design_tokens/") or path.startswith("packages/foodex_visualization/"):
        return "shared-mobile"
    return None


def validate_policy(root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    registry = load_json("component-registry.json", root)
    archetypes = load_json("page-archetypes.json", root)
    golden = load_json("golden-pages.json", root)
    scorecard = load_json("uiux-scorecard.json", root)
    patterns = load_json("forbidden-patterns.json", root)

    for surface, data in registry.get("surfaces", {}).items():
        for rel in data.get("canonical_sources", []):
            if not (root / rel).exists():
                errors.append(f"component-registry: {surface} source missing: {rel}")

    for surface, groups in golden.get("surfaces", {}).items():
        for group, paths in groups.items():
            for rel in paths:
                if not (root / rel).exists():
                    errors.append(f"golden-pages: {surface}/{group} missing: {rel}")

    weights = [int(item.get("weight", 0)) for item in scorecard.get("categories", [])]
    if sum(weights) != 100:
        errors.append(f"uiux-scorecard: category weights must sum to 100, got {sum(weights)}")
    threshold = int(scorecard.get("threshold", 0))
    if threshold < 1 or threshold > 100:
        errors.append("uiux-scorecard: threshold must be between 1 and 100")

    if not archetypes.get("archetypes"):
        errors.append("page-archetypes: at least one archetype is required")

    ids: set[str] = set()
    for rule in patterns.get("rules", []):
        rule_id = str(rule.get("id", "")).strip()
        if not rule_id:
            errors.append("forbidden-patterns: rule without id")
            continue
        if rule_id in ids:
            errors.append(f"forbidden-patterns: duplicate rule id {rule_id}")
        ids.add(rule_id)
        try:
            re.compile(str(rule.get("scope_regex", "")))
            re.compile(str(rule.get("added_regex", "")), re.IGNORECASE | re.MULTILINE | re.DOTALL)
        except re.error as exc:
            errors.append(f"forbidden-patterns: invalid regex for {rule_id}: {exc}")

    return errors


def scan_added(path: str, text: str, rules: list[dict]) -> list[Finding]:
    findings: list[Finding] = []
    for rule in rules:
        if not re.search(str(rule["scope_regex"]), path):
            continue
        if path in set(rule.get("exclude_paths", [])):
            continue
        pattern = re.compile(str(rule["added_regex"]), re.IGNORECASE | re.MULTILINE | re.DOTALL)
        for match in pattern.finditer(text):
            excerpt = " ".join(match.group(0).split())[:180]
            findings.append(
                Finding(
                    rule=str(rule["id"]),
                    severity=str(rule.get("severity", "error")),
                    path=path,
                    message=str(rule["message"]),
                    deduction=int(rule.get("deduction", 0)),
                    excerpt=excerpt,
                )
            )
    return findings


def validate_new_dashboard_page(path: str, status: str, root: Path = ROOT) -> list[Finding]:
    if status != "A":
        return []
    if not (path.startswith("backend/resources/views/admin/") and path.endswith(".blade.php")):
        return []
    name = Path(path).name
    if name.startswith("_") or name in {"login.blade.php"}:
        return []
    content = (root / path).read_text(encoding="utf-8")
    markers = ("admin._brand-components", "foodex-admin-layout", "foodex-admin-main", "foodex-admin-page")
    if any(marker in content for marker in markers):
        return []
    return [
        Finding(
            rule="dashboard-new-page-shell",
            severity="error",
            path=path,
            message="New Dashboard page does not identify the canonical FOODEX brand/layout shell.",
            deduction=30,
        )
    ]


def run_audit(base: str, head: str, root: Path = ROOT) -> tuple[list[Finding], dict]:
    policy_errors = validate_policy(root)
    config = load_json("forbidden-patterns.json", root)
    scorecard = load_json("uiux-scorecard.json", root)
    rows = changed_files(base, head, root)
    ui_rows = [(status, path) for status, path in rows if ui_surface(path)]

    findings: list[Finding] = []
    for status, path in ui_rows:
        if status == "D" or not (root / path).exists():
            continue
        findings.extend(scan_added(path, added_text(base, head, path, root), config.get("rules", [])))
        findings.extend(validate_new_dashboard_page(path, status, root))

    score = max(0, 100 - sum(item.deduction for item in findings))
    errors = [item for item in findings if item.severity == "error"]
    threshold = int(scorecard.get("threshold", 90))
    ok = not policy_errors and not errors and score >= threshold

    report = {
        "schema_version": 1,
        "base": base,
        "head": head,
        "changed_ui_files": [{"status": status, "path": path, "surface": ui_surface(path)} for status, path in ui_rows],
        "policy_errors": policy_errors,
        "findings": [asdict(item) for item in findings],
        "static_compliance_score": score,
        "threshold": threshold,
        "runtime_evidence_required_separately": bool(ui_rows),
        "ok": ok,
    }
    return findings, report


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--report")
    args = parser.parse_args()

    findings, report = run_audit(args.base, args.head, ROOT)
    if args.report:
        target = ROOT / args.report
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    for error in report["policy_errors"]:
        print(f"POLICY ERROR: {error}", file=sys.stderr)
    for item in findings:
        print(
            f"{item.severity.upper()} [{item.rule}] {item.path}: {item.message}"
            + (f" :: {item.excerpt}" if item.excerpt else ""),
            file=sys.stderr if item.severity == "error" else sys.stdout,
        )

    if not report["ok"]:
        print(
            f"FOODEX UIUX changed-surface audit FAILED: score={report['static_compliance_score']} threshold={report['threshold']}",
            file=sys.stderr,
        )
        return 1

    print(
        f"FOODEX UIUX changed-surface audit PASS: files={len(report['changed_ui_files'])} score={report['static_compliance_score']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
