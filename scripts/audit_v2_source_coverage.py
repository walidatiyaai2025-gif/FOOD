#!/usr/bin/env python3
"""G002/P385: fail-closed structural source-to-Issue audit for isolated FOODEX V2.

Does not assert live Issue/PR/CI completion. The original DOCX is deliberately
external to the repository; --source verifies the approved SHA-256 offline.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from zipfile import BadZipFile, ZipFile

from validate_v2_unified_contract import MATRICES, validate as validate_contract

ROOT = Path(__file__).resolve().parents[1]
LEDGER = "docs/v2/V2_2743_SOURCE_REVIEW.json"
PARAGRAPH = re.compile(r"^(?:Native DOCX paragraph )?P(\d+)$")
TABLE = re.compile(r"^(?:Native DOCX )?Table (\d+), data row (\d+)$")
WORD = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"


def verify_partition(kind: str, valid: set[int] | set[tuple[int, int]],
                     mapped: set[int] | set[tuple[int, int]],
                     reviewed: set[int] | set[tuple[int, int]]) -> list[str]:
    errors = []
    if mapped & reviewed:
        errors.append(f"{kind}: source is both mapped and contextual: {sorted(mapped & reviewed)[:12]}")
    if (mapped | reviewed) - valid:
        errors.append(f"{kind}: invalid/out-of-bounds anchors: {sorted((mapped | reviewed) - valid)[:12]}")
    if valid - (mapped | reviewed):
        errors.append(f"{kind}: unreviewed native-source anchors: {sorted(valid - (mapped | reviewed))[:12]}")
    return errors


def docx_shape(path: Path) -> tuple[int, list[int], str]:
    """Only read Word structure; never emit confidential source text."""
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    try:
        with ZipFile(path) as archive:
            root = ET.fromstring(archive.read("word/document.xml"))
    except (BadZipFile, KeyError, ET.ParseError) as exc:
        raise ValueError(f"invalid Word source: {exc}") from exc
    body = root.find(f"{WORD}body")
    if body is None:
        raise ValueError("Word document has no body")
    paragraphs = [x for x in body if x.tag == f"{WORD}p"]
    tables = [x for x in body if x.tag == f"{WORD}tbl"]
    rows = [sum(x.tag == f"{WORD}tr" for x in table) for table in tables]
    return len(paragraphs), rows, digest


def audit(root: Path = ROOT, source: Path | None = None) -> dict:
    errors = validate_contract(root)
    try:
        ledger = json.loads((root / LEDGER).read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        return {"ok": False, "errors": errors + [f"{LEDGER}: unreadable: {exc}"]}

    for key, expected in (("schema", 1), ("owner_issue", 2743),
                          ("source", "FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx"),
                          ("paragraph_count", 374),
                          ("table_data_row_counts", [7, 8, 6, 4, 5, 20, 4, 6, 3, 5, 24, 8, 12])):
        if ledger.get(key) != expected:
            errors.append(f"{LEDGER}: invalid {key}")
    digest = ledger.get("source_sha256")
    if not isinstance(digest, str) or not re.fullmatch(r"[0-9a-f]{64}", digest):
        errors.append(f"{LEDGER}: missing SHA-256 provenance")

    review = ledger.get("contextual_paragraphs", {})
    if not isinstance(review, dict) or set(review) != {"document_metadata", "section_headings", "context_and_source_notes"}:
        errors.append(f"{LEDGER}: explicit contextual paragraph categories required")
        review = {}
    reviewed_paragraphs: set[int] = set()
    for reason, values in review.items():
        if not isinstance(values, list) or any(type(v) is not int for v in values):
            errors.append(f"{LEDGER}: invalid paragraph group {reason}")
            continue
        if len(values) != len(set(values)) or reviewed_paragraphs.intersection(values):
            errors.append(f"{LEDGER}: duplicate paragraph review in {reason}")
        reviewed_paragraphs.update(values)

    table_review = ledger.get("contextual_table_rows", {})
    if not isinstance(table_review, dict) or set(table_review) != {"0", "6"}:
        errors.append(f"{LEDGER}: only source summary Tables 0 and 6 may be excepted")
        table_review = {}
    reviewed_table_rows: set[tuple[int, int]] = set()
    for table_index, details in table_review.items():
        if not isinstance(details, dict) or not isinstance(details.get("issue_references"), list) or not details["issue_references"]:
            errors.append(f"{LEDGER}: summary Table {table_index} needs linked issue references")
            continue
        if not all(type(n) is int and 2503 <= n <= 2925 for n in details["issue_references"]):
            errors.append(f"{LEDGER}: invalid summary Table {table_index} Issue link")
        for row in details.get("data_rows", []):
            if type(row) is not int:
                errors.append(f"{LEDGER}: invalid Table {table_index} row")
            else:
                anchor = (int(table_index), row)
                if anchor in reviewed_table_rows:
                    errors.append(f"{LEDGER}: duplicate table contextual review {anchor}")
                reviewed_table_rows.add(anchor)

    paragraphs: set[int] = set()
    table_rows: set[tuple[int, int]] = set()
    derived: list[int] = []
    for path in MATRICES:
        try:
            items = json.loads((root / path).read_text(encoding="utf-8"))["items"]
        except (OSError, UnicodeError, ValueError, KeyError, TypeError) as exc:
            errors.append(f"{path}: unavailable for source audit: {exc}")
            continue
        for row in items:
            anchor = row.get("document_paragraph_id") or row.get("document_source_pointer", "")
            issue = row.get("issue_number")
            para = PARAGRAPH.fullmatch(anchor)
            tbl = TABLE.fullmatch(anchor)
            if para:
                p = int(para.group(1))
                if p in paragraphs:
                    errors.append(f"{path}: duplicate native paragraph P{p:03d}")
                paragraphs.add(p)
            elif tbl:
                t = (int(tbl.group(1)), int(tbl.group(2)))
                if t in table_rows:
                    errors.append(f"{path}: duplicate native Table/data row {t}")
                table_rows.add(t)
            elif anchor.startswith("Derived "):
                derived.append(issue)
            else:
                errors.append(f"{path}: unsupported native source anchor for Issue #{issue}")

    if sorted(derived) != list(range(2730, 2751)):
        errors.append("derived cross-app/gate issues must be exactly #2730-#2750")
    expected_paragraphs = set(range(ledger.get("paragraph_count") if type(ledger.get("paragraph_count")) is int else 0))
    table_counts = ledger.get("table_data_row_counts")
    expected_rows = {(i, r) for i, n in enumerate(table_counts) for r in range(1, n + 1)} if isinstance(table_counts, list) and all(type(n) is int and n >= 0 for n in table_counts) else set()
    errors.extend(verify_partition("paragraphs", expected_paragraphs, paragraphs, reviewed_paragraphs))
    errors.extend(verify_partition("table data rows", expected_rows, table_rows, reviewed_table_rows))

    source_verified = False
    if source is not None:
        try:
            actual_paragraphs, word_rows, actual_sha = docx_shape(source)
            if actual_sha != digest:
                errors.append("original Word source SHA-256 mismatch")
            if actual_paragraphs != ledger.get("paragraph_count"):
                errors.append("original Word paragraph count differs from reviewed source")
            if word_rows != [n + 1 for n in table_counts]:
                errors.append("original Word table data/header row structure changed")
            source_verified = not errors
        except (OSError, ValueError, TypeError) as exc:
            errors.append(f"cannot verify Word source: {exc}")

    return {"ok": not errors, "source_verified": source_verified,
            "status": "SOURCE_SHA_AND_STRUCTURE_VERIFIED" if source_verified else "MATRIX_REVIEW_ONLY",
            "matrix_issues": len(paragraphs) + len(table_rows) + len(derived),
            "source_paragraphs_mapped": len(paragraphs),
            "source_paragraphs_contextual": len(reviewed_paragraphs),
            "table_data_rows_mapped": len(table_rows),
            "table_data_rows_contextual": len(reviewed_table_rows),
            "table_header_rows": len(table_counts) if isinstance(table_counts, list) else 0,
            "derived_mobile_and_gates": len(derived), "errors": errors}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, help="authorized original DOCX (not committed to Git)")
    parser.add_argument("--report", type=Path, help="JSON summary with no source text/PII")
    args = parser.parse_args()
    report = audit(source=args.source)
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    for message in report["errors"]:
        print(f"::error::{message}", file=sys.stderr)
    print(f"V2 G002 source audit: {report['status']}; {report.get('matrix_issues', 0)} links; "
          f"{report.get('source_paragraphs_mapped', 0)} mapped native paragraphs; "
          f"{report.get('source_paragraphs_contextual', 0)} reviewed context paragraphs; "
          f"{report.get('table_data_rows_mapped', 0)} mapped table rows; "
          f"{report.get('table_data_rows_contextual', 0)} reviewed summary rows")
    print("NOTE: structural traceability only. Live Issue/PR/CI and source-semantic acceptance remain separate gates.")
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
