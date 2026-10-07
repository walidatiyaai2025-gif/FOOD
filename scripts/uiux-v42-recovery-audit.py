#!/usr/bin/env python3
"""Independent static coverage gate for UIUX-V42-RECOVERY (#1041).

This deliberately audits the integrated repository tree rather than only the PR diff.
It complements runtime evidence (#1042); it does not convert visual rows to PASS.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parents[1]
REQUIREMENTS = ROOT / "docs/execution/UIUX_V42_RECOVERY_REQUIREMENTS.json"
MATRIX = ROOT / "docs/execution/UIUX_V42_RECOVERY_REQUIREMENT_MATRIX.md"

IMPLEMENTATION_EVIDENCE = {
    1035: ("matrix-marker", "## #1035 owner evidence checkpoint"),
    1036: ("file", "docs/execution/UIUX_V42_RECOVERY_1036_COMMERCIAL_EVIDENCE.md"),
    1037: ("file", "docs/execution/UIUX_V42_RECOVERY_1037_FIELDOPS_EVIDENCE.md"),
    1038: ("file", "docs/execution/UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md"),
    1039: ("file", "docs/execution/UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md"),
    1040: ("file", "apps/van_app/docs/UIUX_V42_COMPLIANCE_EVIDENCE.md"),
}

LEGACY_INVENTORY = (
    "backend/tests/Feature/AdminNavigationAuthorizationAuditTest.php",
    "backend/tests/Feature/RetailStoreAdminSurfaceAuditTest.php",
    "docs/execution/UIUX_V42_RECOVERY_1037_FIELDOPS_PARITY.md",
    "docs/execution/UIUX_V42_RECOVERY_1038_CUSTOMER_ROUTE_INVENTORY.md",
    "docs/execution/UIUX_V42_RECOVERY_1039_DRIVER_ROUTE_INVENTORY.md",
    "apps/van_app/lib/features/foundation/van_screen_inventory.dart",
)

REQUIRED_BACKEND_CONTRACT_GUARDS = {
    "backend/tests/Feature/AdministrationHubTest.php": (
        "test_administration_hub_exposes_customer_driver_and_van_as_first_class_apps",
        "test_sidebar_groups_follow_business_domain_order_and_keep_administration_last",
    ),
    "backend/tests/Feature/DashboardUiComplianceTest.php": (
        "test_owned_dashboard_views_use_shared_foodex_shell_contract",
        "test_owned_dashboard_views_do_not_expose_routine_raw_identifiers_or_json",
        "test_notification_surfaces_follow_record_action_contract",
        "test_mobile_settings_expose_customer_driver_and_van_as_first_class_apps",
    ),
    "backend/tests/Feature/AdminNavigationAuthorizationAuditTest.php": (
        "test_every_visible_super_admin_navigation_target_opens_without_authorization_or_route_errors",
        "test_every_visible_b2b_admin_navigation_target_opens_without_authorization_or_route_errors",
        "test_every_visible_retail_admin_navigation_target_opens_without_authorization_or_route_errors",
    ),
    "backend/tests/Feature/AdminFilterActionVisualContractTest.php": (
        "test_dashboard_filter_actions_use_canonical_green_style",
    ),
}

REQUIRED_MOBILE_CONTRACT_GUARDS = {
    "apps/customer_app/test/customer_orders_ui_v3_test.dart": (
        "order identifiers stay single-line and actions use one green ellipsis",
        "orders resume refresh keeps stale data visible with truthful offline freshness",
    ),
    "apps/customer_app/test/customer_v42_dynamic_refresh_contract_test.dart": (
        "all canonical wholesale dynamic screens refresh on app resume",
        "customer orders keep both polling and truthful stale evidence",
    ),
    "apps/driver_app/test/driver_active_journey_test.dart": (
        "delivery card keeps no-wrap reference and green ellipsis in compact header",
        "delivery filters stay on one compact row at narrow phone width",
        "foreground polling keeps confirmed deliveries visible and marks offline data stale",
    ),
    "apps/driver_app/test/driver_notification_page_test.dart": (
        "notifications refresh when Driver app resumes",
        "notifications poll in foreground and preserve stale confirmed data on failure",
    ),
    "apps/van_app/test/screenshot_evidence_test.dart": (
        "vanProductionScreenInventory",
        "430",
        "932",
        "360",
        "800",
    ),
    "apps/van_app/docs/UIUX_V42_COMPLIANCE_EVIDENCE.md": (
        "foreground refresh + truthful stale/offline",
        "one-line business labels with ellipsis overflow",
        "430×932",
        "360×800",
    ),
}

MATRIX_ROW_RE = re.compile(
    r"^\|\s*(?P<id>[A-Z]+\d+)\s*\|\s*(?P<summary>.*?)\s*\|\s*#(?P<owner>\d+)\s*\|\s*(?P<evidence>.*?)\s*\|\s*(?P<status>[A-Z_]+)\s*\|\s*$"
)
DYNAMIC_BLADE_RE = re.compile(
    r"\{\{[^}\n]*(?:(?:->)(?:status|state|channel|role|type|payment_method|unit_code)(?![A-Za-z0-9_])|"
    r"\[['\"](?:status|state|channel|role|type|payment_method|unit_code)['\"]\])[^}\n]*\}\}",
    re.IGNORECASE,
)
DYNAMIC_DART_RE = re.compile(
    r"\bText(?:\.rich)?\([^;\n]*(?:"
    r"\.(?:status|state|channel|role|type|paymentMethod|payment_method|unitCode|unit_code)(?![A-Za-z0-9_])|"
    r"\[['\"](?:status|state|channel|role|type|payment_method|unit_code)['\"]\])",
    re.IGNORECASE,
)
ORDER_VALUE_RE = re.compile(
    r"\b(?:order(?:Number|Reference|Id)|assignment(?:Number|Reference|Id)|"
    r"order\.(?:id|number|reference)|assignment\.(?:id|number|reference))\b",
    re.IGNORECASE,
)
RAW_ID_LABEL_RE = re.compile(
    r">\s*(?:User|Store|Driver|Order|Invoice|Campaign|Notification|Device|Log)\s+ID\s*<",
    re.IGNORECASE,
)
TAG_RE = re.compile(r"<(?P<tag>input|textarea)\b(?P<attrs>[^>]*)>", re.IGNORECASE | re.DOTALL)
ATTR_RE = re.compile(r"\b(?P<name>[a-zA-Z_:.-]+)\s*=\s*([\"'])(?P<value>.*?)\2", re.DOTALL)

TECHNICAL_JSON_FIELDS = {"credentials_json"}
LOCALIZATION_MARKERS = (
    "__(",
    "trans(",
    ".tr(",
    "_text(",
    "localized",
    "localised",
    "label",
    "display",
)


def parse_matrix(text: str) -> list[dict[str, object]]:
    rows: list[dict[str, object]] = []
    for raw in text.splitlines():
        match = MATRIX_ROW_RE.match(raw)
        if not match:
            continue
        rows.append(
            {
                "id": match.group("id"),
                "summary": match.group("summary").strip(),
                "owner_issue": int(match.group("owner")),
                "evidence": match.group("evidence").strip(),
                "status": match.group("status"),
            }
        )
    return rows


def validate_requirement_coverage(root: Path = ROOT) -> tuple[list[str], dict[str, object]]:
    errors: list[str] = []
    data = json.loads((root / REQUIREMENTS.relative_to(ROOT)).read_text(encoding="utf-8"))
    matrix_text = (root / MATRIX.relative_to(ROOT)).read_text(encoding="utf-8")
    matrix_rows = parse_matrix(matrix_text)
    json_rows = data.get("requirements", [])

    json_by_id: dict[str, dict[str, object]] = {}
    for row in json_rows:
        rid = str(row.get("id", "")).strip()
        if not rid:
            errors.append("requirements JSON contains a row without an ID")
            continue
        if rid in json_by_id:
            errors.append(f"duplicate requirement ID in JSON: {rid}")
        json_by_id[rid] = row

    matrix_by_id: dict[str, dict[str, object]] = {}
    for row in matrix_rows:
        rid = str(row["id"])
        if rid in matrix_by_id:
            errors.append(f"duplicate requirement ID in matrix: {rid}")
        matrix_by_id[rid] = row

    missing_matrix = sorted(set(json_by_id) - set(matrix_by_id))
    missing_json = sorted(set(matrix_by_id) - set(json_by_id))
    if missing_matrix:
        errors.append("requirements missing from matrix: " + ", ".join(missing_matrix))
    if missing_json:
        errors.append("matrix requirements missing from JSON: " + ", ".join(missing_json))

    forbidden = {"UNKNOWN", "UNOWNED"}
    for rid in sorted(set(json_by_id) & set(matrix_by_id)):
        source = json_by_id[rid]
        matrix = matrix_by_id[rid]
        owner = source.get("owner_issue")
        if not isinstance(owner, int):
            errors.append(f"{rid}: JSON owner_issue must be an integer")
        if owner != matrix["owner_issue"]:
            errors.append(
                f"{rid}: owner mismatch JSON #{owner} vs matrix #{matrix['owner_issue']}"
            )
        if not str(source.get("evidence", "")).strip():
            errors.append(f"{rid}: JSON evidence field is empty")
        if not str(matrix.get("evidence", "")).strip():
            errors.append(f"{rid}: matrix evidence field is empty")
        if str(source.get("status", "")).upper() in forbidden:
            errors.append(f"{rid}: forbidden JSON status {source.get('status')}")
        if str(matrix.get("status", "")).upper() in forbidden:
            errors.append(f"{rid}: forbidden matrix status {matrix.get('status')}")

    for owner, (kind, value) in IMPLEMENTATION_EVIDENCE.items():
        owned = [rid for rid, row in json_by_id.items() if row.get("owner_issue") == owner]
        if not owned:
            errors.append(f"implementation owner #{owner} owns no requirement rows")
            continue
        if kind == "file":
            if not (root / value).is_file():
                errors.append(f"owner #{owner} evidence artifact is missing: {value}")
        elif value not in matrix_text:
            errors.append(f"owner #{owner} matrix evidence checkpoint is missing: {value}")

    for relative in LEGACY_INVENTORY:
        if not (root / relative).is_file():
            errors.append(f"legacy route/screen inventory artifact is missing: {relative}")

    report = {
        "mission": data.get("mission_id"),
        "requirement_count": len(json_by_id),
        "matrix_requirement_count": len(matrix_by_id),
        "owners": sorted({row.get("owner_issue") for row in json_rows if isinstance(row.get("owner_issue"), int)}),
        "implementation_evidence_owners": sorted(IMPLEMENTATION_EVIDENCE),
        "legacy_inventory_artifacts": list(LEGACY_INVENTORY),
    }
    return errors, report


def validate_backend_contract_guards(root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    for relative, required_tokens in REQUIRED_BACKEND_CONTRACT_GUARDS.items():
        path = root / relative
        if not path.is_file():
            errors.append(f"required Dashboard contract guard is missing: {relative}")
            continue
        text = path.read_text(encoding="utf-8")
        for token in required_tokens:
            if token not in text:
                errors.append(f"{relative}: required contract guard is missing: {token}")
    return errors


def validate_mobile_contract_guards(root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    for relative, required_tokens in REQUIRED_MOBILE_CONTRACT_GUARDS.items():
        path = root / relative
        if not path.is_file():
            errors.append(f"required mobile contract guard is missing: {relative}")
            continue
        text = path.read_text(encoding="utf-8")
        for token in required_tokens:
            if token not in text:
                errors.append(f"{relative}: required mobile contract evidence is missing: {token}")
    return errors


def _dart_text_blocks(text: str) -> Iterable[tuple[int, str]]:
    lines = text.splitlines()
    for index, line in enumerate(lines):
        if not re.search(r"\bText(?:\.rich)?\s*\(", line):
            continue
        block: list[str] = []
        balance = 0
        saw_open = False
        for candidate in lines[index:index + 32]:
            block.append(candidate)
            balance += candidate.count("(") - candidate.count(")")
            if re.search(r"\bText(?:\.rich)?\s*\(", candidate):
                saw_open = True
            if saw_open and balance <= 0:
                break
        yield index + 1, "\n".join(block)


def scan_mobile_layout_guardrails(root: Path = ROOT) -> list[str]:
    findings: list[str] = []
    for app, path in iter_mobile_ui_files(root):
        text = path.read_text(encoding="utf-8")
        relative = path.relative_to(root).as_posix()

        for lineno, line in enumerate(text.splitlines(), start=1):
            toolbar = re.search(r"toolbarHeight\s*:\s*(-?\d+(?:\.\d+)?)", line)
            if toolbar and float(toolbar.group(1)) > 72:
                findings.append(
                    f"{relative}:{lineno}: [{app}] toolbarHeight {toolbar.group(1)} exceeds compact 72px guardrail"
                )
            expanded = re.search(r"expandedHeight\s*:\s*(-?\d+(?:\.\d+)?)", line)
            if expanded and float(expanded.group(1)) > 120:
                findings.append(
                    f"{relative}:{lineno}: [{app}] expandedHeight {expanded.group(1)} is an oversized mobile header"
                )

        for lineno, block in _dart_text_blocks(text):
            if ORDER_VALUE_RE.search(block) and not re.search(
                r"(maxLines\s*:\s*1\b|softWrap\s*:\s*false\b|overflow\s*:\s*TextOverflow\.)",
                block,
            ):
                findings.append(
                    f"{relative}:{lineno}: [{app}] order/assignment identifier Text lacks an explicit no-wrap contract"
                )
    return findings


def _attrs(tag: str) -> dict[str, str]:
    return {m.group("name").lower(): m.group("value") for m in ATTR_RE.finditer(tag)}


def _line_number(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def _privileged_advanced_context(text: str, offset: int) -> bool:
    context = text[max(0, offset - 1800):offset].lower()
    advanced = any(
        marker in context
        for marker in (
            "data-advanced",
            "data-privileged",
            "commercial-advanced",
            "advanced technical",
        )
    )
    privileged = any(
        marker in context
        for marker in (
            "@if($issuper",
            "$canmanagefeatureflags",
            "super_admin",
            "super admin",
        )
    )
    nearest_boundary = max(context.rfind("<details"), context.rfind("</details>"), context.rfind("<section"))
    scoped = context[nearest_boundary:] if nearest_boundary >= 0 else context
    scoped_advanced = any(marker in scoped for marker in ("data-advanced", "data-privileged", "commercial-advanced"))
    return advanced and privileged and scoped_advanced


def scan_admin_raw_inputs(root: Path = ROOT) -> list[str]:
    findings: list[str] = []
    view_root = root / "backend/resources/views/admin"
    for path in sorted(view_root.rglob("*.blade.php")):
        text = path.read_text(encoding="utf-8")
        relative = path.relative_to(root).as_posix()

        for match in TAG_RE.finditer(text):
            tag = match.group(0)
            attrs = _attrs(tag)
            name = attrs.get("name", "")
            input_type = attrs.get("type", "text").lower()
            if name.endswith("_id") and input_type != "hidden":
                findings.append(
                    f"{relative}:{_line_number(text, match.start())}: routine typed internal-ID input {name}"
                )

            if not name.endswith("_json") or input_type == "hidden":
                continue
            if name in TECHNICAL_JSON_FIELDS:
                continue
            if _privileged_advanced_context(text, match.start()):
                continue
            findings.append(
                f"{relative}:{_line_number(text, match.start())}: visible raw JSON control {name} is not explicitly privileged/Advanced"
            )

        for lineno, line in enumerate(text.splitlines(), start=1):
            if RAW_ID_LABEL_RE.search(line):
                findings.append(f"{relative}:{lineno}: raw internal ID label is visible")
            if DYNAMIC_BLADE_RE.search(line):
                lower = line.lower()
                if not any(marker.lower() in lower for marker in LOCALIZATION_MARKERS):
                    findings.append(
                        f"{relative}:{lineno}: state/channel/role/type/payment/unit is rendered without localization"
                    )
    return findings


def iter_mobile_ui_files(root: Path = ROOT) -> Iterable[tuple[str, Path]]:
    for app in ("customer", "driver", "van"):
        base = root / f"apps/{app}_app/lib"
        for path in sorted(base.rglob("*.dart")):
            relative = path.relative_to(root).as_posix()
            if any(part in relative for part in ("/core/api/", "/models/", "/repositories/", "/repository/", "/data/")):
                continue
            yield app, path


def scan_mobile_dynamic_enums(root: Path = ROOT) -> list[str]:
    findings: list[str] = []
    for app, path in iter_mobile_ui_files(root):
        text = path.read_text(encoding="utf-8")
        relative = path.relative_to(root).as_posix()
        for lineno, block in _dart_text_blocks(text):
            if not DYNAMIC_DART_RE.search(block):
                continue
            lower = block.lower()
            if any(marker.lower() in lower for marker in LOCALIZATION_MARKERS):
                continue
            findings.append(
                f"{relative}:{lineno}: [{app}] state/channel/role/type/payment/unit is rendered directly"
            )
    return findings


def run_audit(root: Path = ROOT) -> tuple[list[str], dict[str, object]]:
    errors, report = validate_requirement_coverage(root)
    backend_contract_errors = validate_backend_contract_guards(root)
    mobile_contract_errors = validate_mobile_contract_guards(root)
    admin_findings = scan_admin_raw_inputs(root)
    mobile_findings = scan_mobile_dynamic_enums(root)
    mobile_layout_findings = scan_mobile_layout_guardrails(root)
    errors.extend(backend_contract_errors)
    errors.extend(mobile_contract_errors)
    errors.extend(admin_findings)
    errors.extend(mobile_findings)
    errors.extend(mobile_layout_findings)
    report.update(
        {
            "backend_contract_guard_errors": len(backend_contract_errors),
            "mobile_contract_guard_errors": len(mobile_contract_errors),
            "admin_raw_input_findings": len(admin_findings),
            "mobile_dynamic_enum_findings": len(mobile_findings),
            "mobile_layout_guardrail_findings": len(mobile_layout_findings),
            "result": "PASS" if not errors else "FAIL",
            "errors": errors,
        }
    )
    return errors, report


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", help="optional JSON report output path")
    args = parser.parse_args()

    errors, report = run_audit(ROOT)
    if args.report:
        output = ROOT / args.report
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    if errors:
        print("UIUX-V42-RECOVERY independent static audit FAILED:", file=sys.stderr)
        for error in errors:
            print(f" - {error}", file=sys.stderr)
        return 1

    print(
        "UIUX-V42-RECOVERY independent static audit PASS: "
        f"{report['requirement_count']} requirements have unique ownership/evidence; "
        "legacy inventories are present; no high-signal raw-input/localization violation was found."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
