#!/usr/bin/env python3
"""FOODEX structural parity and skill anti-drift gate."""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from dataclasses import asdict, dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


@dataclass(frozen=True)
class Finding:
    rule: str
    severity: str
    path: str
    message: str


def load(relative: str, root: Path = ROOT) -> dict:
    return json.loads((root / relative).read_text(encoding="utf-8"))


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(["git", "-C", str(root), *args], text=True)


def changed_files(base: str, head: str, root: Path = ROOT) -> list[tuple[str, str]]:
    rows: list[tuple[str, str]] = []
    for raw in git(root, "diff", "--name-status", "--find-renames", base, head).splitlines():
        parts = raw.split("\t")
        if not parts:
            continue
        status = parts[0]
        if status.startswith("R") and len(parts) >= 3:
            rows.append(("R", parts[2]))
        elif len(parts) >= 2:
            rows.append((status[:1], parts[1]))
    return rows


def surface_for(path: str, config: dict) -> str | None:
    for surface, data in config.get("surfaces", {}).items():
        if re.search(str(data.get("screen_regex", r"$^")), path):
            return surface
    return None


def group_errors(content: str, groups: list[dict]) -> list[str]:
    errors: list[str] = []
    for group in groups:
        all_markers = list(group.get("all", []))
        any_markers = list(group.get("any", []))
        if all_markers and not all(marker in content for marker in all_markers):
            errors.append(str(group.get("id", "required-group")))
        if any_markers and not any(marker in content for marker in any_markers):
            errors.append(str(group.get("id", "required-group")))
    return errors


def marker_features(content: str, tokens: list[str]) -> set[str]:
    return {token for token in tokens if token in content}


def jaccard(left: set[str], right: set[str]) -> float:
    union = left | right
    if not union:
        return 1.0
    return len(left & right) / len(union)


def golden_paths(surface: str, root: Path = ROOT) -> list[str]:
    golden = load(".ai/uiux/golden-pages.json", root)
    groups = golden.get("surfaces", {}).get(surface, {})
    result: list[str] = []
    for paths in groups.values():
        result.extend(paths)
    return result


def best_golden_match(path: str, surface: str, tokens: list[str], root: Path = ROOT) -> dict:
    target = (root / path).read_text(encoding="utf-8")
    target_features = marker_features(target, tokens)
    best = {"path": None, "score": 0.0, "shared_markers": []}
    for rel in golden_paths(surface, root):
        candidate = root / rel
        if not candidate.is_file():
            continue
        features = marker_features(candidate.read_text(encoding="utf-8"), tokens)
        score = jaccard(target_features, features)
        if score > best["score"]:
            best = {
                "path": rel,
                "score": round(score, 4),
                "shared_markers": sorted(target_features & features),
            }
    return best


def drift_findings(config: dict, root: Path = ROOT) -> list[Finding]:
    findings: list[Finding] = []
    for surface, files in config.get("drift_sources", {}).items():
        for rel, markers in files.items():
            path = root / rel
            if not path.is_file():
                findings.append(Finding("structural-drift-source", "error", rel, f"Missing {surface} drift source."))
                continue
            content = path.read_text(encoding="utf-8")
            for marker in markers:
                if marker not in content:
                    findings.append(Finding("structural-drift-marker", "error", rel, f"Missing canonical marker: {marker}"))
    return findings


def run(base: str, head: str, root: Path = ROOT) -> tuple[list[Finding], dict]:
    config = load(".ai/uiux/structural-signatures.json", root)
    threshold = float(config.get("advisory_similarity_threshold", 0.18))
    rows = changed_files(base, head, root)
    findings = drift_findings(config, root)
    screens: list[dict] = []

    for status, path in rows:
        surface = surface_for(path, config)
        if not surface or status == "D" or not (root / path).is_file():
            continue
        data = config["surfaces"][surface]
        content = (root / path).read_text(encoding="utf-8")
        missing = group_errors(content, data.get("required_groups", [])) if status == "A" else []
        for group in missing:
            findings.append(Finding(
                "new-screen-structural-signature",
                "error",
                path,
                f"New {surface} screen is missing required structural group: {group}",
            ))
        best = best_golden_match(path, surface, list(data.get("similarity_tokens", [])), root)
        if best["path"] and best["score"] < threshold:
            findings.append(Finding(
                "golden-structural-similarity",
                "warning",
                path,
                f"Best Golden structural similarity is {best['score']:.2f} against {best['path']}; review the intended archetype.",
            ))
        screens.append({
            "status": status,
            "path": path,
            "surface": surface,
            "new_screen": status == "A",
            "missing_required_groups": missing,
            "best_golden_match": best,
        })

    errors = [item for item in findings if item.severity == "error"]
    report = {
        "schema_version": 1,
        "base": base,
        "head": head,
        "screens": screens,
        "findings": [asdict(item) for item in findings],
        "advisory_similarity_threshold": threshold,
        "ok": not errors,
    }
    return findings, report


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--report")
    args = parser.parse_args()
    findings, report = run(args.base, args.head, ROOT)
    if args.report:
        target = ROOT / args.report
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    for item in findings:
        stream = sys.stderr if item.severity == "error" else sys.stdout
        print(f"{item.severity.upper()} [{item.rule}] {item.path}: {item.message}", file=stream)
    if not report["ok"]:
        print("FOODEX UI structural parity audit FAILED.", file=sys.stderr)
        return 1
    print(f"FOODEX UI structural parity audit PASS: screens={len(report['screens'])}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
