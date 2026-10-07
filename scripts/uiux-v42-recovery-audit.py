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
            if name.endswith("_id") and input_type == "number":
                findings.append(
                    f"{relative}:{_line_number(text, match.start())}: routine numeric internal-ID input {name}"
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
        for lineno, line in enumerate(text.splitlines(), start=1):
            if not DYNAMIC_DART_RE.search(line):
                continue
            lower = line.lower()
            if any(marker.lower() in lower for marker in LOCALIZATION_MARKERS):
                continue
            findings.append(
                f"{relative}:{lineno}: [{app}] state/channel/role/type/payment/unit is rendered directly"
            )
    return findings


def run_audit(root: Path = ROOT) -> tuple[list[str], dict[str, object]]:
    errors, report = validate_requirement_coverage(root)
    admin_findings = scan_admin_raw_inputs(root)
    mobile_findings = scan_mobile_dynamic_enums(root)
    errors.extend(admin_findings)
    errors.extend(mobile_findings)
    report.update(
        {
            "admin_raw_input_findings": len(admin_findings),
            "mobile_dynamic_enum_findings": len(mobile_findings),
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
