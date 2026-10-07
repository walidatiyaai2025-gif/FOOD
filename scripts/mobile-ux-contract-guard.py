#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Sequence

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = ROOT / "docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md"

APP_ROOTS = {
    "customer": Path("apps/customer_app/lib"),
    "driver": Path("apps/driver_app/lib"),
    "van": Path("apps/van_app/lib"),
}

REQUIRED_CONTRACT_TOKENS = (
    "## 14. Compact mobile layout contract - Customer / Driver / Van",
    "### 14.1 Compact title/header footprint",
    "### 14.2 Use the full available screen",
    "### 14.3 One-line filter bars",
    "### 14.4 Order number must never wrap",
    "### 14.5 Compact list/grid rows",
    "### 14.6 Row actions use one ellipsis menu",
    "### 14.8 Mobile layout review gate",
)

ORDER_VALUE_RE = re.compile(
    r"\b(?:order(?:Number|Reference|Id)|order\.(?:id|number|reference)|"
    r"assignment\.(?:id|number|reference))\b",
    re.IGNORECASE,
)
BUTTON_RE = re.compile(
    r"\b(?:TextButton|FilledButton|ElevatedButton|OutlinedButton|IconButton)"
    r"(?:\.icon)?\s*\("
)


@dataclass(frozen=True)
class PatchLine:
    kind: str
    text: str
    line_no: int | None


@dataclass(frozen=True)
class Hunk:
    path: str
    lines: tuple[PatchLine, ...]


@dataclass(frozen=True)
class Finding:
    app: str
    path: str
    rule: str
    line_no: int | None
    message: str

    def render(self) -> str:
        location = self.path
        if self.line_no is not None:
            location += f":{self.line_no}"
        return f"{location}: [{self.app}/{self.rule}] {self.message}"


def _float_after(label: str, text: str) -> float | None:
    match = re.search(rf"{re.escape(label)}\s*:\s*(-?\d+(?:\.\d+)?)", text)
    if not match:
        return None
    return float(match.group(1))


def validate_repository_contract(root: Path = ROOT) -> list[str]:
    errors: list[str] = []

    missing_apps = [
        app for app, rel in APP_ROOTS.items() if not (root / rel).is_dir()
    ]
    if missing_apps:
        errors.append(
            "mobile UX guard must cover Customer, Driver and Van; missing app roots: "
            + ", ".join(sorted(missing_apps))
        )

    contract = root / CONTRACT.relative_to(ROOT)
    if not contract.is_file():
        errors.append(f"authoritative UI/UX contract is missing: {contract}")
        return errors

    content = contract.read_text(encoding="utf-8")
    for token in REQUIRED_CONTRACT_TOKENS:
        if token not in content:
            errors.append(
                f"authoritative UI/UX contract is missing required mobile clause: {token}"
            )
    return errors


def parse_unified_diff(diff_text: str) -> list[Hunk]:
    hunks: list[Hunk] = []
    current_path: str | None = None
    current_lines: list[PatchLine] | None = None
    new_line = 0

    def flush() -> None:
        nonlocal current_lines
        if current_path is not None and current_lines:
            hunks.append(Hunk(path=current_path, lines=tuple(current_lines)))
        current_lines = None

    for raw in diff_text.splitlines():
        if raw.startswith("+++ "):
            flush()
            value = raw[4:]
            if value == "/dev/null":
                current_path = None
            elif value.startswith("b/"):
                current_path = value[2:]
            else:
                current_path = value
            continue

        if raw.startswith("@@ "):
            flush()
            if current_path is None:
                continue
            match = re.search(r"\+(\d+)(?:,\d+)?", raw)
            if not match:
                continue
            new_line = int(match.group(1))
            current_lines = []
            continue

        if current_lines is None or current_path is None:
            continue

        if raw.startswith("+") and not raw.startswith("+++"):
            current_lines.append(PatchLine("+", raw[1:], new_line))
            new_line += 1
        elif raw.startswith("-") and not raw.startswith("---"):
            current_lines.append(PatchLine("-", raw[1:], None))
        else:
            text = raw[1:] if raw.startswith(" ") else raw
            current_lines.append(PatchLine(" ", text, new_line))
            new_line += 1

    flush()
    return hunks


def hunk_from_source(path: str, source: str) -> Hunk:
    return Hunk(
        path=path,
        lines=tuple(
            PatchLine("+", line, index)
            for index, line in enumerate(source.splitlines(), start=1)
        ),
    )


def _added_lines(hunk: Hunk) -> list[PatchLine]:
    return [line for line in hunk.lines if line.kind == "+"]


def _context_text(hunk: Hunk) -> str:
    return "\n".join(line.text for line in hunk.lines if line.kind != "-")


def _added_text(hunk: Hunk) -> str:
    return "\n".join(line.text for line in _added_lines(hunk))


def _find_text_widget_blocks(hunk: Hunk) -> Iterable[tuple[list[PatchLine], bool]]:
    lines = [line for line in hunk.lines if line.kind != "-"]
    i = 0
    while i < len(lines):
        if "Text(" not in lines[i].text:
            i += 1
            continue

        block: list[PatchLine] = []
        balance = 0
        saw_open = False
        j = i
        while j < len(lines) and j < i + 24:
            line = lines[j]
            block.append(line)
            balance += line.text.count("(") - line.text.count(")")
            if "Text(" in line.text:
                saw_open = True
            if saw_open and balance <= 0:
                break
            j += 1
        yield block, any(line.kind == "+" for line in block)
        i = max(j + 1, i + 1)


def validate_hunk(app: str, hunk: Hunk) -> list[Finding]:
    findings: list[Finding] = []
    added = _added_lines(hunk)
    if not added:
        return findings

    added_text = _added_text(hunk)
    context_text = _context_text(hunk)
    context_lower = context_text.lower()

    for line in added:
        toolbar = _float_after("toolbarHeight", line.text)
        if toolbar is not None and toolbar > 72:
            findings.append(
                Finding(
                    app,
                    hunk.path,
                    "compact-header",
                    line.line_no,
                    f"toolbarHeight {toolbar:g} exceeds the 72px compact-header guardrail",
                )
            )
        expanded = _float_after("expandedHeight", line.text)
        if expanded is not None and expanded > 120:
            findings.append(
                Finding(
                    app,
                    hunk.path,
                    "compact-header",
                    line.line_no,
                    f"expandedHeight {expanded:g} is an oversized mobile header",
                )
            )
        if any(token in context_lower for token in ("header", "subtitle", "page title")):
            sized = re.search(
                r"\bSizedBox\s*\(\s*height\s*:\s*(\d+(?:\.\d+)?)",
                line.text,
            )
            if sized and float(sized.group(1)) > 96:
                findings.append(
                    Finding(
                        app,
                        hunk.path,
                        "compact-header",
                        line.line_no,
                        f"header SizedBox height {sized.group(1)} wastes mobile data area",
                    )
                )

    if (
        "body:" in context_text
        and "Center(" in added_text
        and "ConstrainedBox(" in added_text
    ):
        for line in added:
            max_width = _float_after("maxWidth", line.text)
            if max_width is not None and max_width <= 520:
                findings.append(
                    Finding(
                        app,
                        hunk.path,
                        "full-width",
                        line.line_no,
                        f"centered maxWidth {max_width:g} creates a narrow mobile page shell",
                    )
                )

    filter_context = (
        re.search(r"\bstart(?:Date|Time|_date|_time)?\b", context_text, re.I)
        and re.search(r"\bend(?:Date|Time|_date|_time)?\b", context_text, re.I)
        and re.search(
            r"(TextField|DropdownButton|showDatePicker|filter|date range|dateRange)",
            context_text,
            re.I,
        )
    )
    if filter_context and "Column(" in added_text:
        has_horizontal_layout = (
            "Row(" in context_text
            or "Wrap(" in context_text
            or (
                "SingleChildScrollView(" in context_text
                and "Axis.horizontal" in context_text
            )
            or "ListView(" in context_text
            and "Axis.horizontal" in context_text
        )
        if not has_horizontal_layout:
            first = next(
                (line for line in added if "Column(" in line.text),
                added[0],
            )
            findings.append(
                Finding(
                    app,
                    hunk.path,
                    "one-line-filters",
                    first.line_no,
                    "Start/End filter controls are added in a Column without a same-line/horizontal layout",
                )
            )

    for block, contains_addition in _find_text_widget_blocks(hunk):
        if not contains_addition:
            continue
        block_text = "\n".join(line.text for line in block)
        if not ORDER_VALUE_RE.search(block_text):
            continue
        if re.search(
            r"(maxLines\s*:\s*1\b|softWrap\s*:\s*false\b|"
            r"overflow\s*:\s*TextOverflow\.)",
            block_text,
        ):
            continue
        added_in_block = [line for line in block if line.kind == "+"]
        line_no = added_in_block[0].line_no if added_in_block else block[0].line_no
        findings.append(
            Finding(
                app,
                hunk.path,
                "order-nowrap",
                line_no,
                "order/assignment identifier Text must declare maxLines: 1, softWrap: false, or a controlled TextOverflow",
            )
        )

    row_context = bool(
        re.search(
            r"(itemBuilder\s*:|ListTile\s*\(|ListView(?:\.separated|\.builder)?\s*\()",
            context_text,
        )
    )
    if row_context:
        button_count = len(list(BUTTON_RE.finditer(added_text)))
        has_ellipsis = bool(
            re.search(
                r"(PopupMenuButton|Icons\.more_vert|Icons\.more_horiz)",
                context_text,
            )
        )
        if button_count >= 2 and not has_ellipsis:
            first_button_line = next(
                (line.line_no for line in added if BUTTON_RE.search(line.text)),
                added[0].line_no,
            )
            findings.append(
                Finding(
                    app,
                    hunk.path,
                    "ellipsis-actions",
                    first_button_line,
                    "mobile list row adds multiple inline action buttons without one ellipsis menu",
                )
            )

    return findings


def _git(root: Path, *args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=root,
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip()
        raise RuntimeError(f"git {' '.join(args)} failed: {detail}")
    return result.stdout


def build_diff(root: Path, base: str, head: str) -> str:
    roots = [str(path) for path in APP_ROOTS.values()]
    if head == "WORKTREE":
        diff = _git(
            root,
            "diff",
            "--unified=12",
            "--no-ext-diff",
            base,
            "--",
            *roots,
        )
        untracked = _git(
            root,
            "ls-files",
            "--others",
            "--exclude-standard",
            "--",
            *roots,
        )
        parts = [diff]
        for raw_path in untracked.splitlines():
            path = Path(raw_path)
            if path.suffix != ".dart":
                continue
            source = (root / path).read_text(encoding="utf-8")
            plus = "\n".join(f"+{line}" for line in source.splitlines())
            parts.append(
                f"diff --git a/{path} b/{path}\n"
                f"--- /dev/null\n"
                f"+++ b/{path}\n"
                f"@@ -0,0 +1,{len(source.splitlines())} @@\n{plus}\n"
            )
        return "\n".join(parts)

    return _git(
        root,
        "diff",
        "--unified=12",
        "--no-ext-diff",
        base,
        head,
        "--",
        *roots,
    )


def _app_for_path(path: str) -> str | None:
    for app, root in APP_ROOTS.items():
        prefix = f"{root.as_posix()}/"
        if path.startswith(prefix):
            return app
    return None


def validate_diff(diff_text: str) -> list[Finding]:
    findings: list[Finding] = []
    for hunk in parse_unified_diff(diff_text):
        if not hunk.path.endswith(".dart"):
            continue
        app = _app_for_path(hunk.path)
        if app is None:
            continue
        findings.extend(validate_hunk(app, hunk))

    unique: dict[tuple[str, str, int | None, str], Finding] = {}
    for finding in findings:
        key = (finding.path, finding.rule, finding.line_no, finding.message)
        unique[key] = finding
    return list(unique.values())


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Cheap deterministic anti-regression guard for the FOODEX v4.2 "
            "Customer/Driver/Van compact mobile UX contract."
        )
    )
    parser.add_argument("--base", required=True, help="base Git ref/SHA")
    parser.add_argument(
        "--head",
        default="HEAD",
        help="head Git ref/SHA or WORKTREE (default: HEAD)",
    )
    args = parser.parse_args(argv)

    errors = validate_repository_contract(ROOT)
    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1

    try:
        diff_text = build_diff(ROOT, args.base, args.head)
    except RuntimeError as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 2

    findings = validate_diff(diff_text)
    if findings:
        print(
            "FOODEX mobile UX v4.2 guard found deterministic anti-regression "
            "violations:",
            file=sys.stderr,
        )
        for finding in findings:
            print(f"  - {finding.render()}", file=sys.stderr)
        print(
            "Authoritative contract: "
            "docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md §14",
            file=sys.stderr,
        )
        return 1

    touched_apps = sorted(
        {
            app
            for hunk in parse_unified_diff(diff_text)
            if (app := _app_for_path(hunk.path)) is not None
        }
    )
    touched = ", ".join(touched_apps) if touched_apps else "none"
    print(
        "FOODEX mobile UX v4.2 guard PASS "
        f"(tri-app contract verified; changed app roots: {touched})."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
