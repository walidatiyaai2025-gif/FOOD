#!/usr/bin/env python3
"""FOODEX production-backed business UI intent router and casebook validator."""
from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]


def load_json(relative: str, root: Path = ROOT) -> dict[str, Any]:
    return json.loads((root / relative).read_text(encoding="utf-8"))


def normalize(text: str) -> str:
    value = unicodedata.normalize("NFKC", text or "").lower().replace("ـ", "")
    value = re.sub(r"[\u064b-\u065f\u0670]", "", value)
    value = value.translate(str.maketrans({"أ": "ا", "إ": "ا", "آ": "ا", "ى": "ي", "ؤ": "و", "ئ": "ي"}))
    value = re.sub(r"[^0-9a-z\u0600-\u06ff]+", " ", value)
    return " ".join(value.split())


def recipe_map(root: Path = ROOT) -> dict[str, dict[str, Any]]:
    payload = load_json(".ai/uiux/business-recipes.json", root)
    return {str(item["id"]): item for item in payload.get("recipes", [])}


def case_list(root: Path = ROOT) -> list[dict[str, Any]]:
    return list(load_json(".ai/uiux/business-casebook.json", root).get("cases", []))


def authority_ids(root: Path = ROOT) -> dict[str, set[str]]:
    registry = load_json("docs/execution/UI_ROUTE_AUTHORITY.json", root)
    result: dict[str, set[str]] = {}
    for surface, data in registry.get("surfaces", {}).items():
        ids: set[str] = set()
        for bucket in ("functions", "compatibility"):
            for item in data.get(bucket, []):
                if isinstance(item, dict) and item.get("id"):
                    ids.add(str(item["id"]))
        result[surface] = ids
    return result


def validate_casebook(root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    recipes_payload = load_json(".ai/uiux/business-recipes.json", root)
    cases_payload = load_json(".ai/uiux/business-casebook.json", root)
    archetypes = load_json(".ai/uiux/page-archetypes.json", root).get("archetypes", {})
    routes = authority_ids(root)

    if recipes_payload.get("schema_version") != 1:
        errors.append("business-recipes schema_version must be 1")
    if cases_payload.get("schema_version") != 1:
        errors.append("business-casebook schema_version must be 1")

    recipes = recipe_map(root)
    if len(recipes) != len(recipes_payload.get("recipes", [])):
        errors.append("business-recipes contains duplicate ids")

    seen: set[str] = set()
    for case in cases_payload.get("cases", []):
        case_id = str(case.get("id", "")).strip()
        surface = str(case.get("surface", "")).strip()
        archetype = str(case.get("archetype", "")).strip()
        if not case_id:
            errors.append("business-casebook contains a case without id")
            continue
        if case_id in seen:
            errors.append(f"duplicate business case id: {case_id}")
        seen.add(case_id)

        if case.get("recipe_id") not in recipes:
            errors.append(f"{case_id}: unknown recipe {case.get('recipe_id')}")
        archetype_data = archetypes.get(archetype)
        if not isinstance(archetype_data, dict):
            errors.append(f"{case_id}: unknown archetype {archetype}")
        else:
            allowed = archetype_data.get("surfaces", [])
            if allowed and surface not in allowed:
                errors.append(f"{case_id}: archetype {archetype} does not apply to {surface}")

        for key in ("intent_phrases_ar", "intent_phrases_en", "primary_actions", "business_invariants"):
            if not case.get(key):
                errors.append(f"{case_id}: {key} must not be empty")

        for rel in list(case.get("production_sources", [])) + list(case.get("tests", [])):
            if not (root / rel).is_file():
                errors.append(f"{case_id}: missing referenced file {rel}")

        for rel, markers in case.get("source_markers", {}).items():
            path = root / rel
            if not path.is_file():
                errors.append(f"{case_id}: marker source missing {rel}")
                continue
            content = path.read_text(encoding="utf-8")
            for marker in markers:
                if marker not in content:
                    errors.append(f"{case_id}: source marker missing in {rel}: {marker}")

        for function_id in case.get("canonical_function_ids", []):
            if function_id not in routes.get(surface, set()):
                errors.append(f"{case_id}: canonical function id not registered for {surface}: {function_id}")

    return errors


def score_case(intent: str, case: dict[str, Any]) -> int:
    value = normalize(intent)
    score = 0
    for phrase in list(case.get("intent_phrases_ar", [])) + list(case.get("intent_phrases_en", [])):
        normalized = normalize(str(phrase))
        if normalized and normalized in value:
            score += 12
    for keyword in list(case.get("keywords_ar", [])) + list(case.get("keywords_en", [])):
        normalized = normalize(str(keyword))
        if normalized and normalized in value:
            score += 2
    for hint in case.get("surface_hints", []):
        normalized = normalize(str(hint))
        if normalized and normalized in value:
            score += 1
    for negative in case.get("negative_keywords", []):
        normalized = normalize(str(negative))
        if normalized and normalized in value:
            score -= 6
    return score


def route_intent(
    intent: str,
    *,
    surface: str | None = None,
    case_id: str | None = None,
    root: Path = ROOT,
) -> dict[str, Any]:
    cases = case_list(root)
    config = load_json(".ai/uiux/business-casebook.json", root).get("routing", {})
    min_score = int(config.get("min_score", 5))
    margin = int(config.get("ambiguity_margin", 3))

    if case_id:
        for case in cases:
            if case.get("id") == case_id:
                if surface and case.get("surface") != surface:
                    raise ValueError(f"Case {case_id} belongs to {case.get('surface')}, not {surface}")
                return {"resolved": True, "ambiguous": False, "selected": case, "score": None, "candidates": []}
        raise ValueError(f"Unknown business case id: {case_id}")

    scored: list[tuple[int, dict[str, Any]]] = []
    for case in cases:
        if surface and case.get("surface") != surface:
            continue
        scored.append((score_case(intent, case), case))
    scored.sort(key=lambda item: (-item[0], str(item[1].get("id"))))

    candidates = [{"id": case["id"], "surface": case["surface"], "score": score} for score, case in scored[:5]]
    if not scored or scored[0][0] < min_score:
        return {"resolved": False, "ambiguous": False, "selected": None, "score": scored[0][0] if scored else 0, "candidates": candidates}

    top_score, top_case = scored[0]
    second_score = scored[1][0] if len(scored) > 1 else -999
    ambiguous = second_score >= min_score and (top_score - second_score) < margin
    if ambiguous:
        return {"resolved": False, "ambiguous": True, "selected": None, "score": top_score, "candidates": candidates}

    return {"resolved": True, "ambiguous": False, "selected": top_case, "score": top_score, "candidates": candidates}


def authority_entries(case: dict[str, Any], root: Path = ROOT) -> list[dict[str, Any]]:
    registry = load_json("docs/execution/UI_ROUTE_AUTHORITY.json", root)
    surface_data = registry.get("surfaces", {}).get(case.get("surface"), {})
    wanted = set(case.get("canonical_function_ids", []))
    result: list[dict[str, Any]] = []
    for bucket in ("functions", "compatibility"):
        for item in surface_data.get(bucket, []):
            if item.get("id") in wanted:
                result.append({"bucket": bucket, **item})
    return result


def build_plan(result: dict[str, Any], root: Path = ROOT) -> dict[str, Any]:
    if not result.get("resolved"):
        return {
            "schema_version": 1,
            "resolved": False,
            "ambiguous": bool(result.get("ambiguous")),
            "candidates": result.get("candidates", []),
            "instruction": "Use explicit Issue/route/surface context to resolve the business case; do not invent a journey.",
        }

    case = result["selected"]
    recipes = recipe_map(root)
    surface = str(case["surface"])
    quality = load_json(".ai/uiux/quality-gates.json", root)
    visual = load_json(".ai/uiux/visual-evidence-matrix.json", root)
    context = load_json(".ai/uiux/context-contract.json", root)
    golden = load_json(".ai/uiux/golden-pages.json", root)

    golden_candidates: list[str] = []
    for paths in golden.get("surfaces", {}).get(surface, {}).values():
        golden_candidates.extend(paths)

    return {
        "schema_version": 1,
        "resolved": True,
        "business_case": case,
        "business_recipe": recipes[case["recipe_id"]],
        "route_authority": authority_entries(case, root),
        "context_required_inputs": context.get("required_inputs", []),
        "quality_gates": quality.get("surfaces", {}).get(surface, {}),
        "visual_evidence": visual.get("surfaces", {}).get(surface, {}),
        "golden_page_candidates": golden_candidates,
        "precedence_note": "Explicit Issue/route/source authority outranks inferred intent.",
    }


def write_output(payload: dict[str, Any], output: str | None, root: Path = ROOT) -> None:
    rendered = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
    if output:
        target = root / output
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(rendered, encoding="utf-8")
    else:
        print(rendered, end="")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--intent")
    parser.add_argument("--surface", choices=["dashboard", "customer", "driver", "van"])
    parser.add_argument("--case-id")
    parser.add_argument("--strict", action="store_true")
    parser.add_argument("--validate-only", action="store_true")
    parser.add_argument("--output")
    args = parser.parse_args()

    errors = validate_casebook(ROOT)
    if args.validate_only:
        report = {"schema_version": 1, "validation_errors": errors, "case_count": len(case_list(ROOT)), "recipe_count": len(recipe_map(ROOT)), "ok": not errors}
        write_output(report, args.output, ROOT)
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1 if errors else 0

    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1
    if not args.intent and not args.case_id:
        print("ERROR: --intent or --case-id is required", file=sys.stderr)
        return 1

    try:
        result = route_intent(args.intent or "", surface=args.surface, case_id=args.case_id, root=ROOT)
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1

    plan = build_plan(result, ROOT)
    write_output(plan, args.output, ROOT)
    if args.strict and not plan.get("resolved"):
        print("ERROR: UI business intent is unresolved or ambiguous; inspect Issue/route context.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
