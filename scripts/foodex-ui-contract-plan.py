#!/usr/bin/env python3
"""Generate a FOODEX UI implementation contract from repository-native policy."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]


def load_json(relative: str, root: Path = ROOT) -> dict[str, Any]:
    return json.loads((root / relative).read_text(encoding="utf-8"))


def find_function(surface: str, function_id: str | None, root: Path = ROOT) -> dict[str, Any] | None:
    if not function_id:
        return None
    registry = load_json("docs/execution/UI_ROUTE_AUTHORITY.json", root)
    data = registry.get("surfaces", {}).get(surface, {})
    for bucket in ("functions", "compatibility"):
        for item in data.get(bucket, []):
            if item.get("id") == function_id:
                return {"bucket": bucket, **item}
    raise ValueError(f"Unknown canonical function id for {surface}: {function_id}")


def build_plan(
    surface: str,
    archetype: str,
    *,
    function_id: str | None = None,
    route: str | None = None,
    root: Path = ROOT,
) -> dict[str, Any]:
    components = load_json(".ai/uiux/component-registry.json", root)
    archetypes = load_json(".ai/uiux/page-archetypes.json", root)
    golden = load_json(".ai/uiux/golden-pages.json", root)
    gates = load_json(".ai/uiux/quality-gates.json", root)
    context = load_json(".ai/uiux/context-contract.json", root)
    route_registry = load_json("docs/execution/UI_ROUTE_AUTHORITY.json", root)

    if surface not in {"dashboard", "customer", "driver", "van"}:
        raise ValueError(f"Unsupported surface: {surface}")
    surface_components = components.get("surfaces", {}).get(surface)
    if not isinstance(surface_components, dict):
        raise ValueError(f"Missing component registry for surface: {surface}")

    archetype_data = archetypes.get("archetypes", {}).get(archetype)
    if not isinstance(archetype_data, dict):
        raise ValueError(f"Unknown page archetype: {archetype}")
    allowed = archetype_data.get("surfaces", [])
    if allowed and surface not in allowed:
        raise ValueError(f"Archetype {archetype} does not apply to {surface}")

    authority = route_registry.get("surfaces", {}).get(surface, {})
    function = find_function(surface, function_id, root)
    effective_route = route or (function or {}).get("route")

    golden_groups = golden.get("surfaces", {}).get(surface, {})
    candidates: list[dict[str, Any]] = []
    for group, paths in golden_groups.items():
        candidates.append({"group": group, "paths": paths})

    return {
        "schema_version": 1,
        "surface": surface,
        "archetype": archetype,
        "function": function,
        "route": effective_route,
        "route_authority": {
            key: authority.get(key)
            for key in ("authority_source", "router", "shell", "sidebar", "api_authority_source")
            if authority.get(key)
        },
        "production_sources": surface_components.get("canonical_sources", []),
        "shared_primitives": surface_components.get("primitives", {}),
        "archetype_contract": archetype_data,
        "golden_page_candidates": candidates,
        "context_required_inputs": context.get("required_inputs", []),
        "context_rules": context.get("rules", {}),
        "quality_gates": gates.get("surfaces", {}).get(surface, {}),
        "cross_surface_gates": gates.get("cross_surface", {}),
        "authority_documents": context.get("authority", {}),
        "notes": [
            "Resolve Store/channel/tenant context before visual implementation.",
            "Currency/precision must come from authoritative domain data when money is present.",
            "Runtime visual evidence remains separate from static policy compliance."
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--surface", required=True, choices=["dashboard", "customer", "driver", "van"])
    parser.add_argument("--archetype", required=True)
    parser.add_argument("--function-id")
    parser.add_argument("--route")
    parser.add_argument("--output")
    args = parser.parse_args()

    try:
        plan = build_plan(
            args.surface,
            args.archetype,
            function_id=args.function_id,
            route=args.route,
            root=ROOT,
        )
    except (ValueError, FileNotFoundError, json.JSONDecodeError) as exc:
        print(f"ERROR: {exc}")
        return 1

    rendered = json.dumps(plan, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        target = ROOT / args.output
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(rendered, encoding="utf-8")
        print(target)
    else:
        print(rendered, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
