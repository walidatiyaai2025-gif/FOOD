#!/usr/bin/env python3
"""FOODEX bilingual localization quality gate.

The gate is intentionally differential for UI literals: it blocks new regressions
without forcing unrelated legacy cleanup in the same PR. Catalog parity checks run
against the complete current tree.
"""
from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

UI_DART = re.compile(r"^apps/(customer_app|driver_app|van_app)/lib/.*\.dart$")
UI_BLADE = re.compile(r"^backend/resources/views/.*\.blade\.php$")
SKIP_DIFF = re.compile(r"(?:/test/|/tests/|core/localization/|\.g\.dart$|\.freezed\.dart$)")

DART_LITERAL_PATTERNS = [
    re.compile(r"""\bText\s*\(\s*(?:const\s+)?(?P<q>['"])(?P<text>[^'"]*[A-Za-z\u0600-\u06FF][^'"]*)(?P=q)"""),
    re.compile(r"""\b(?:labelText|hintText|helperText|errorText|tooltip|semanticLabel)\s*:\s*(?P<q>['"])(?P<text>[^'"]*[A-Za-z\u0600-\u06FF][^'"]*)(?P=q)"""),
]
BLADE_TEXT_NODE = re.compile(r">\s*(?P<text>[^<{]*[A-Za-z\u0600-\u06FF][^<{]*)\s*<")
BLADE_ATTR = re.compile(
    r"""\b(?:title|placeholder|aria-label|alt)\s*=\s*(?P<q>['"])(?P<text>[^'"]*[A-Za-z\u0600-\u06FF][^'"]*)(?P=q)""",
    re.IGNORECASE,
)

RAW_BUSINESS_FIELD = re.compile(
    r"\b(status|state|type|channel|payment_?method|paymentMethod|role|unit_?code|unitCode)\b",
    re.IGNORECASE,
)
DIRECT_LOCALE_FIELD = re.compile(r"\b(name_?(?:ar|en)|name(?:Ar|En)|title_?(?:ar|en)|title(?:Ar|En))\b")

LOCALIZED_MARKERS = (
    "__(",
    "@lang",
    "trans(",
    "translate",
    "translation",
    "localized",
    "localised",
    "_text(",
    ".tr(",
    ".t(",
    "label",
)
ALLOW_MARKER = "localization-gate: allow"


def run(*args: str) -> str:
    return subprocess.check_output(args, cwd=ROOT, text=True).strip()


def changed_files(base: str, head: str) -> list[str]:
    out = run("git", "diff", "--name-only", base, head)
    return [line.strip() for line in out.splitlines() if line.strip()]


def added_lines(path: str, base: str, head: str) -> list[tuple[int, str]]:
    proc = subprocess.run(
        ["git", "diff", "--unified=0", "--no-color", base, head, "--", path],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        check=True,
    )
    result: list[tuple[int, str]] = []
    new_line = 0
    for raw in proc.stdout.splitlines():
        if raw.startswith("@@"):
            match = re.search(r"\+(\d+)(?:,(\d+))?", raw)
            if match:
                new_line = int(match.group(1))
            continue
        if raw.startswith("+++") or raw.startswith("---"):
            continue
        if raw.startswith("+"):
            result.append((new_line, raw[1:]))
            new_line += 1
        elif raw.startswith("-"):
            continue
        else:
            new_line += 1
    return result


def _looks_localized(line: str) -> bool:
    lower = line.lower()
    return any(marker.lower() in lower for marker in LOCALIZED_MARKERS)


def scan_added_line(path: str, line: str) -> list[str]:
    stripped = line.strip()
    if not stripped or stripped.startswith("//") or stripped.startswith("#"):
        return []
    if ALLOW_MARKER in line:
        return []

    problems: list[str] = []

    if UI_DART.match(path):
        for pattern in DART_LITERAL_PATTERNS:
            match = pattern.search(line)
            if match and not _looks_localized(line):
                text = match.group("text").strip()
                problems.append(
                    f"new hard-coded Flutter user text [{text}] must use the active locale catalog/bilingual resolver"
                )

        if "Text(" in line and RAW_BUSINESS_FIELD.search(line):
            if not _looks_localized(line):
                problems.append(
                    "raw business enum/status/type/channel/payment/role data is rendered directly; resolve a localized label first"
                )

        if "Text(" in line and DIRECT_LOCALE_FIELD.search(line):
            if "localized" not in line.lower() and "locale" not in line.lower():
                problems.append(
                    "locale-specific data field (ar/en) is rendered directly; select it through the active-locale resolver"
                )

    elif UI_BLADE.match(path):
        if not _looks_localized(line):
            for pattern in (BLADE_TEXT_NODE, BLADE_ATTR):
                match = pattern.search(line)
                if match:
                    text = match.group("text").strip()
                    if text and not text.startswith("@"):
                        problems.append(
                            f"new hard-coded Blade user text [{text}] must use Laravel localization"
                        )
                        break

        if "{{" in line and RAW_BUSINESS_FIELD.search(line) and not _looks_localized(line):
            problems.append(
                "raw business enum/status/type/channel/payment/role data is rendered directly; use a localized display label"
            )

        if "{{" in line and DIRECT_LOCALE_FIELD.search(line):
            lower = line.lower()
            if "localized" not in lower and "locale" not in lower and "app()->getlocale" not in lower:
                problems.append(
                    "locale-specific data field (ar/en) is rendered directly; use the active-locale resolver"
                )

    return problems


def extract_dart_catalog_keys(path: Path, map_name: str) -> set[str]:
    text = path.read_text(encoding="utf-8")
    marker = f"static const Map<String, String> {map_name} = {{"
    start = text.find(marker)
    if start < 0:
        raise RuntimeError(f"{path}: missing {map_name} catalog")
    end = text.find("\n  };", start)
    if end < 0:
        raise RuntimeError(f"{path}: unterminated {map_name} catalog")
    block = text[start:end]
    return set(re.findall(r"'([^']+)'\s*:", block))


def check_dart_catalog(path: Path) -> list[str]:
    problems: list[str] = []
    ar = extract_dart_catalog_keys(path, "_ar")
    en = extract_dart_catalog_keys(path, "_en")
    only_ar = sorted(ar - en)
    only_en = sorted(en - ar)
    if only_ar:
        problems.append(f"{path}: keys missing in English: {', '.join(only_ar[:20])}")
    if only_en:
        problems.append(f"{path}: keys missing in Arabic: {', '.join(only_en[:20])}")
    return problems


def php_keys(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    return re.findall(r"""['"]([^'"]+)['"]\s*=>""", text)


def check_backend_catalogs() -> list[str]:
    problems: list[str] = []
    ar_dir = ROOT / "backend/lang/ar"
    en_dir = ROOT / "backend/lang/en"
    ar_files = {p.name for p in ar_dir.glob("*.php")}
    en_files = {p.name for p in en_dir.glob("*.php")}
    if ar_files != en_files:
        if ar_files - en_files:
            problems.append("backend lang files missing in English: " + ", ".join(sorted(ar_files - en_files)))
        if en_files - ar_files:
            problems.append("backend lang files missing in Arabic: " + ", ".join(sorted(en_files - ar_files)))

    for name in sorted(ar_files & en_files):
        ar_keys = php_keys(ar_dir / name)
        en_keys = php_keys(en_dir / name)
        if len(ar_keys) != len(en_keys) or set(ar_keys) != set(en_keys):
            missing_en = sorted(set(ar_keys) - set(en_keys))
            missing_ar = sorted(set(en_keys) - set(ar_keys))
            problems.append(
                f"backend/lang/{name} parity mismatch; "
                f"missing EN={missing_en[:12]} missing AR={missing_ar[:12]} "
                f"counts AR={len(ar_keys)} EN={len(en_keys)}"
            )
    return problems


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    args = parser.parse_args()

    problems: list[str] = []
    problems.extend(
        check_dart_catalog(ROOT / "apps/customer_app/lib/core/localization/app_translations.dart")
    )
    problems.extend(
        check_dart_catalog(ROOT / "apps/driver_app/lib/core/localization/driver_translations.dart")
    )
    problems.extend(check_backend_catalogs())

    for path in changed_files(args.base, args.head):
        if SKIP_DIFF.search(path):
            continue
        if not (UI_DART.match(path) or UI_BLADE.match(path)):
            continue
        for line_no, line in added_lines(path, args.base, args.head):
            for message in scan_added_line(path, line):
                problems.append(f"{path}:{line_no}: {message}\n    + {line.strip()}")

    if problems:
        print("FOODEX Localization Quality Gate: FAILED", file=sys.stderr)
        for problem in problems:
            print(f"::error::{problem}", file=sys.stderr)
        print(
            "\nSystem-owned UI/data must follow the selected locale. "
            "Add both Arabic and English translations or use the approved localized resolver. "
            f"For exceptional technical text only, document the reason with '{ALLOW_MARKER}'.",
            file=sys.stderr,
        )
        return 1

    print("FOODEX Localization Quality Gate: PASS")
    print("Arabic/English catalog parity is intact and no new unlocalized UI/data rendering was detected.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
