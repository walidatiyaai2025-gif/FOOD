#!/usr/bin/env python3
"""FOODEX V2 P385: fail-closed source-to-Issue governance contract gate.

This validates provenance and required evidence vocabulary, NOT deployed runtime
behavior or completion of the 422 individual implementation/acceptance issues.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = "FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx"
MATRICES = {
    "docs/v2/V2_SIDEBAR_ISSUE_MATRIX.json": 177,
    "docs/v2/V2_ERP_MOBILE_GATES_MATRIX.json": 70,
    "docs/v2/V2_FUNCTIONAL_ISSUE_MATRIX.json": 155,
    "docs/v2/V2_ACCOUNT_EXPORT_AUDIT_MATRIX.json": 20,
}
CONTROLS = {
    "UX": {"navigation_reachability", "ar_en_rtl_ltr_accessibility", "responsive_browser_evidence"},
    "Backend": {"route_authority", "data_validation", "direct_http_allow_deny"},
    "RBAC": {"permission_derived_sidebar", "scoped_role_allow_deny", "tenant_channel_store_isolation"},
    "ERP": {"module_entitlement_readiness", "maker_checker_audit", "credit_finance_fulfillment_invariants"},
}
EVIDENCE = {
    "source_line", "persona", "entry_point", "canonical_route", "permission",
    "tenant_store_channel", "module_entitlement_readiness", "ar_en_rtl_ltr",
    "responsive_accessibility", "allow_deny_regression", "mobile_impact",
    "exact_head_ci", "integration_sha",
}
EXPECTED_ISSUES = set(range(2504, 2926))


def read_json(root: Path, path: str, errors: list[str]) -> dict | None:
    try:
        value = json.loads((root / path).read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        errors.append(f"{path}: unreadable JSON: {exc}")
        return None
    if not isinstance(value, dict):
        errors.append(f"{path}: JSON root must be an object")
        return None
    return value


def validate(root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    path = "docs/v2/V2_UNIFIED_GOVERNANCE_CONTRACT.json"
    c = read_json(root, path, errors)
    if c is None:
        return errors

    for key, expected in (
        ("schema", 1),
        ("owner_issue", 2905),
        ("parent_issue", 2503),
        ("integration_branch", "Foodex_V2"),
        ("source_document", SOURCE),
        ("source_matrices", MATRICES),
    ):
        if c.get(key) != expected:
            errors.append(f"{path}: invalid {key}")

    dimensions = c.get("dimensions")
    if not isinstance(dimensions, dict) or set(dimensions) != set(CONTROLS):
        errors.append(f"{path}: must define all four UX/Backend/RBAC/ERP dimensions")
    else:
        for name, mandatory in CONTROLS.items():
            dim = dimensions[name]
            if not isinstance(dim, dict):
                errors.append(f"{path}: invalid dimension {name}")
                continue
            if not isinstance(dim.get("authority"), str) or not dim["authority"].strip():
                errors.append(f"{path}: missing authority for {name}")
            controls = dim.get("controls")
            if not isinstance(controls, list) or not mandatory.issubset(set(
                item for item in controls if isinstance(item, str)
            )):
                errors.append(f"{path}: incomplete controls for {name}")

    acceptance = c.get("acceptance")
    if not isinstance(acceptance, dict):
        errors.append(f"{path}: acceptance evidence required")
    else:
        evidence = acceptance.get("required_evidence")
        if not isinstance(evidence, list) or not EVIDENCE.issubset(set(
            item for item in evidence if isinstance(item, str)
        )):
            errors.append(f"{path}: missing mandatory per-Issue evidence")
        if acceptance.get("mobile_apps") != ["Customer", "Driver", "Van"]:
            errors.append(f"{path}: explicit Customer, Driver, Van assessments required")
        if acceptance.get("backend_denial_status") != 403:
            errors.append(f"{path}: HTTP direct-route deny must remain 403")
        if acceptance.get("requires_linked_app_issue_if_affected") is not True:
            errors.append(f"{path}: affected apps require linked V2 Issues")

    invariants = c.get("invariants")
    required_invariants = {
        "b2b_fulfillment": "VAN_ONLY",
        "b2c_fulfillment": "DRIVER_ONLY",
        "no_sidebar_privilege_grant": True,
        "no_enabled_implies_erp_ready": True,
        "no_unapproved_main_promotion": True,
        "no_production_release": True,
    }
    if not isinstance(invariants, dict) or any(
        invariants.get(k) != v for k, v in required_invariants.items()
    ):
        errors.append(f"{path}: security/fulfillment/isolation invariants incomplete")

    seen_issues: dict[int, str] = {}
    p385_found = False
    for matrix_path, count in MATRICES.items():
        m = read_json(root, matrix_path, errors)
        if m is None:
            continue
        for key, expected in (
            ("schema", 1), ("source", SOURCE),
            ("parent_issue", 2503), ("base", "Foodex_V2"),
        ):
            if m.get(key) != expected:
                errors.append(f"{matrix_path}: invalid {key}")

        rows = m.get("items")
        if not isinstance(rows, list):
            errors.append(f"{matrix_path}: items must be a list")
            continue
        if len(rows) != count:
            errors.append(f"{matrix_path}: expected {count} source-linked rows; got {len(rows)}")

        row_ids: set[str] = set()
        for index, row in enumerate(rows):
            loc = f"{matrix_path}: item {index + 1}"
            if not isinstance(row, dict):
                errors.append(f"{loc}: must be an object")
                continue
            row_id = row.get("id")
            if not isinstance(row_id, str) or not row_id.strip() or row_id in row_ids:
                errors.append(f"{loc}: missing or duplicate source key")
            else:
                row_ids.add(row_id)
            if not isinstance(row.get("title"), str) or not row["title"].startswith("V2"):
                errors.append(f"{loc}: issue title must begin V2")
            num = row.get("issue_number")
            if type(num) is not int or num not in EXPECTED_ISSUES:
                errors.append(f"{loc}: issue number outside 422-child source range")
                continue
            if num in seen_issues:
                errors.append(f"{loc}: duplicate issue #{num} (also {seen_issues[num]})")
            else:
                seen_issues[num] = loc
            if row.get("url") != f"https://github.com/walidatiyaai2025-gif/FOOD/issues/{num}":
                errors.append(f"{loc}: URL does not match canonical source Issue")
            pointer_key = (
                "document_paragraph_id" if "SIDEBAR_" in matrix_path
                else "document_source_pointer"
            )
            pointer = row.get(pointer_key)
            if not isinstance(pointer, str) or not pointer.strip():
                errors.append(f"{loc}: missing native DOCX source pointer")
            if "SIDEBAR_" in matrix_path and not re.fullmatch(r"P\d+", str(pointer)):
                errors.append(f"{loc}: invalid native sidebar paragraph ID")
            if num == 2905:
                p385_found = (
                    matrix_path.endswith("V2_FUNCTIONAL_ISSUE_MATRIX.json")
                    and row_id == "P385"
                    and pointer == "Native DOCX paragraph P372"
                )
                if not p385_found:
                    errors.append(f"{loc}: incorrect owner Issue #2905 / P385 source anchor")

    missing = EXPECTED_ISSUES - set(seen_issues)
    if missing:
        errors.append(f"missing {len(missing)} source-linked Issues: {sorted(missing)[:12]}")
    if not p385_found:
        errors.append("P385 governance owner #2905 is absent or mislinked")
    return errors


def main() -> int:
    errors = validate()
    if errors:
        for error in errors:
            print(f"::error::{error}", file=sys.stderr)
        return 1
    print("V2 P385 unified contract OK: 4 matrices, 422 unique Issues; all four controls/evidence dimensions verified.")
    print("NOTE: provenance gate only. Runtime/app acceptance remains per-Issue and master #2503 stays OPEN.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
