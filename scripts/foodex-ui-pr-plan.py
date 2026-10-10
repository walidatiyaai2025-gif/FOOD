#!/usr/bin/env python3
"""Generate and optionally enforce exact-diff FOODEX UI evidence requirements."""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


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


def screen_surface(path: str, signatures: dict) -> str | None:
    for surface, data in signatures.get("surfaces", {}).items():
        if re.search(str(data.get("screen_regex", r"$^")), path):
            return surface
    return None


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


def golden_candidates(surface: str, root: Path = ROOT) -> list[str]:
    groups = load(".ai/uiux/golden-pages.json", root).get("surfaces", {}).get(surface, {})
    result: list[str] = []
    for paths in groups.values():
        result.extend(paths)
    return result


def inventory_errors(rows: list[tuple[str, str]], signatures: dict, matrix: dict) -> list[str]:
    changed = {path for _, path in rows}
    errors: list[str] = []
    new_by_surface: dict[str, list[str]] = {}
    for status, path in rows:
        if status != "A":
            continue
        surface = screen_surface(path, signatures)
        if surface:
            new_by_surface.setdefault(surface, []).append(path)
    for surface, screens in sorted(new_by_surface.items()):
        inventory = matrix.get("surfaces", {}).get(surface, {}).get("evidence_inventory")
        if not inventory:
            errors.append(f"{surface}: no evidence inventory configured for new screens {screens}")
        elif inventory not in changed:
            errors.append(
                f"{surface}: new screen(s) require evidence inventory update in {inventory}: {', '.join(screens)}"
            )
    return errors


def validate_matrix(matrix: dict, root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    for surface, data in matrix.get("surfaces", {}).items():
        for key in ("workflow", "evidence_inventory"):
            rel = data.get(key)
            if not isinstance(rel, str) or not (root / rel).is_file():
                errors.append(f"{surface}: invalid {key}: {rel}")
        if not data.get("locales"):
            errors.append(f"{surface}: visual matrix locales are empty")
        if not data.get("viewports"):
            errors.append(f"{surface}: visual matrix viewports are empty")
        if not data.get("required_ci_job"):
            errors.append(f"{surface}: required_ci_job is missing")
    return errors


def build_plan(rows: list[tuple[str, str]], root: Path = ROOT) -> dict:
    signatures = load(".ai/uiux/structural-signatures.json", root)
    matrix = load(".ai/uiux/visual-evidence-matrix.json", root)
    quality = load(".ai/uiux/quality-gates.json", root)
    context = load(".ai/uiux/context-contract.json", root)

    changed_ui: dict[str, list[dict]] = {}
    new_screens: dict[str, list[str]] = {}
    shared_mobile = False
    for status, path in rows:
        surface = ui_surface(path)
        if surface == "shared-mobile":
            shared_mobile = True
            continue
        if surface:
            changed_ui.setdefault(surface, []).append({"status": status, "path": path})
        screen = screen_surface(path, signatures)
        if status == "A" and screen:
            new_screens.setdefault(screen, []).append(path)

    if shared_mobile:
        for surface in ("customer", "driver", "van"):
            changed_ui.setdefault(surface, []).append({"status": "M", "path": "shared-mobile-design-system"})

    surfaces = sorted(changed_ui)
    errors = validate_matrix(matrix, root) + inventory_errors(rows, signatures, matrix)
    plans = {}
    for surface in surfaces:
        plans[surface] = {
            "changed_files": changed_ui[surface],
            "new_screens": new_screens.get(surface, []),
            "golden_page_candidates": golden_candidates(surface, root),
            "quality_gates": quality.get("surfaces", {}).get(surface, {}),
            "visual_evidence": matrix.get("surfaces", {}).get(surface, {}),
        }

    return {
        "schema_version": 1,
        "ui_changed": bool(surfaces),
        "surfaces": surfaces,
        "surface_plans": plans,
        "context_required_inputs": context.get("required_inputs", []),
        "cross_surface_gates": quality.get("cross_surface", {}),
        "strict_errors": errors,
        "ok": not errors,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--output")
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()
    rows = changed_files(args.base, args.head, ROOT)
    plan = build_plan(rows, ROOT)
    rendered = json.dumps(plan, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        target = ROOT / args.output
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(rendered, encoding="utf-8")
    else:
        print(rendered, end="")
    for error in plan["strict_errors"]:
        print(f"ERROR: {error}", file=sys.stderr)
    if args.strict and not plan["ok"]:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
