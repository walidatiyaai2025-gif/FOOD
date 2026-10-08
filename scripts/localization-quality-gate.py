#!/usr/bin/env python3
"""FOODEX localization quality gate.

The gate is intentionally incremental for UI literals: existing legacy screens are
not rewritten by this check, but new/changed UI must not introduce untranslated
user-facing literals. Translation catalog key parity is checked globally.
"""
from __future__ import annotations

import argparse
import re
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parents[1]

DART_TRANSLATION_FILES = (
    ROOT / "apps/customer_app/lib/core/localization/app_translations.dart",
    ROOT / "apps/driver_app/lib/core/localization/driver_translations.dart",
)

TECHNICAL_TOKENS = {
    "FOODEX", "API", "SKU", "QR", "JSON", "ID", "UUID", "URL", "HTTP", "HTTPS",
    "GPS", "OTP", "PDF", "CSV", "APK", "B2B", "B2C", "KNET", "RTL", "LTR",
    "GET", "POST", "PUT", "PATCH", "DELETE", "OK",
}

VISIBLE_DART_LITERAL_PATTERNS = (
    re.compile(r"\bText(?:\.rich)?\(\s*(?:const\s*)?(['\"])(?P<text>.*?)(?<!\\)\1"),
    re.compile(
        r"\b(?:labelText|hintText|helperText|errorText|tooltip|semanticLabel|"
        r"title|subtitle|label)\s*:\s*(?:const\s*)?(['\"])(?P<text>.*?)(?<!\\)\1"
    ),
)

DYNAMIC_ENUM_PATTERN = re.compile(
    r"\bText\([^\n]*(?:\.(?:status|state|channel|role|type|payment_method|paymentMethod|unit_code|unitCode)|\[['\"](?:status|state|channel|role|type|payment_method|unit_code)['\"]\])",
    re.IGNORECASE,
)

BLADE_DYNAMIC_ENUM_PATTERN = re.compile(
    r"\{\{[^\n}]*(?:->(?:status|state|channel|role|type|payment_method|unit_code)|\[['\"](?:status|state|channel|role|type|payment_method|unit_code)['\"]\])[^\n}]*\}\}",
    re.IGNORECASE,
)

DIRECT_LOCALE_FIELD_PATTERN = re.compile(
    r"\b(?:name_(?:ar|en)|name(?:Ar|En)|title_(?:ar|en)|title(?:Ar|En)|"
    r"description_(?:ar|en)|description(?:Ar|En))\b"
)

ALLOW_MARKER = "localization-gate: allow"

DART_ENTRY = re.compile(
    r"^\s*(['\"])(?P<key>[^'\"]+)\1\s*:\s*(['\"])(?P<value>.*?)(?<!\\)\3\s*,?\s*$"
)
PHP_ENTRY = re.compile(
    r"^\s*(['\"])(?P<key>[^'\"]+)\1\s*=>\s*(['\"])(?P<value>.*?)(?<!\\)\3\s*,?\s*$"
)

ARABIC_RE = re.compile(r"[\u0600-\u06FF]")
LATIN_RE = re.compile(r"[A-Za-z]")


def run(*args: str) -> str:
    proc = subprocess.run(args, cwd=ROOT, text=True, capture_output=True)
    if proc.returncode != 0:
        raise RuntimeError(
            f"command failed ({proc.returncode}): {' '.join(args)}\n"
            f"{proc.stdout}{proc.stderr}"
        )
    return proc.stdout


def is_technical_only(value: str) -> bool:
    candidate = re.sub(r"[^A-Za-z0-9]+", " ", value).strip()
    if not candidate:
        return True
    words = candidate.split()
    return all(word in TECHNICAL_TOKENS or word.isdigit() for word in words)


def looks_like_technical_literal(value: str) -> bool:
    value = value.strip()
    if not value:
        return True
    if ARABIC_RE.search(value):
        return False
    if value in {"ar", "en", "∞", "-", "—", "…"}:
        return True
    if value.startswith(("/", "http://", "https://", "assets/", "package:")):
        return True
    if re.fullmatch(r"[a-z0-9_.:-]+", value) and ("." in value or "_" in value or "/" in value):
        return True
    if re.fullmatch(r"#[0-9A-Fa-f]{3,8}", value):
        return True
    if is_technical_only(value):
        return True
    return False


def blade_line_is_inside_nonvisible_block(path: str, lineno: int) -> bool:
    """Return True when a Blade source line is inside a script/style block.

    The localization gate checks rendered HTML wording, not JavaScript/CSS syntax.
    Looking at the complete checked-out file avoids false positives when only an
    inner script/style line is part of the git diff.
    """
    file_path = ROOT / path
    if not file_path.is_file() or lineno < 1:
        return False

    text = file_path.read_text(encoding="utf-8")
    lines = text.splitlines()
    if lineno > len(lines):
        return False

    prefix = "\n".join(lines[:lineno]).lower()
    for tag in ("script", "style"):
        opening = prefix.rfind(f"<{tag}")
        closing = prefix.rfind(f"</{tag}>")
        if opening > closing:
            return True

    return False


def extract_dart_map(text: str, name: str) -> tuple[set[str], tuple[int, int]]:
    marker = re.search(
        rf"static\s+const\s+Map<String,\s*String>\s+{re.escape(name)}\s*=\s*\{{",
        text,
    )
    if not marker:
        raise ValueError(f"translation map {name} not found")
    start = marker.end()
    end_match = re.search(r"^\s*\};", text[start:], flags=re.MULTILINE)
    if not end_match:
        raise ValueError(f"translation map {name} has no closing brace")
    end = start + end_match.start()
    body = text[start:end]
    keys = set(re.findall(r"^\s*['\"]([^'\"]+)['\"]\s*:", body, flags=re.MULTILINE))
    start_line = text[:start].count("\n") + 1
    end_line = text[:end].count("\n") + 1
    return keys, (start_line, end_line)


def check_dart_catalog_parity(errors: list[str]) -> None:
    for path in DART_TRANSLATION_FILES:
        if not path.is_file():
            errors.append(f"missing translation catalog: {path.relative_to(ROOT)}")
            continue
        text = path.read_text(encoding="utf-8")
        try:
            ar, _ = extract_dart_map(text, "_ar")
            en, _ = extract_dart_map(text, "_en")
        except ValueError as exc:
            errors.append(f"{path.relative_to(ROOT)}: {exc}")
            continue
        missing_en = sorted(ar - en)
        missing_ar = sorted(en - ar)
        if missing_en:
            errors.append(
                f"{path.relative_to(ROOT)}: Arabic keys missing from English map: "
                + ", ".join(missing_en[:20])
            )
        if missing_ar:
            errors.append(
                f"{path.relative_to(ROOT)}: English keys missing from Arabic map: "
                + ", ".join(missing_ar[:20])
            )


def flatten_php_keys(path: Path) -> set[str] | None:
    if shutil.which("php") is None:
        return None
    php = r'''
    $file = $argv[1];
    $data = require $file;
    if (!is_array($data)) { fwrite(STDERR, "translation file must return array\n"); exit(2); }
    $out = [];
    $walk = function ($value, $prefix = "") use (&$walk, &$out) {
        foreach ($value as $key => $child) {
            $path = $prefix === "" ? (string)$key : $prefix.".".$key;
            if (is_array($child)) { $walk($child, $path); } else { $out[] = $path; }
        }
    };
    $walk($data);
    sort($out);
    echo implode("\n", $out);
    '''
    proc = subprocess.run(
        ["php", "-r", php, str(path)],
        cwd=ROOT,
        text=True,
        capture_output=True,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"{path.relative_to(ROOT)}: {proc.stderr.strip()}")
    return {line for line in proc.stdout.splitlines() if line}


def check_backend_catalog_parity(errors: list[str]) -> None:
    ar_dir = ROOT / "backend/lang/ar"
    en_dir = ROOT / "backend/lang/en"
    if not ar_dir.is_dir() or not en_dir.is_dir():
        errors.append("backend/lang/ar and backend/lang/en must both exist")
        return

    ar_files = {p.name for p in ar_dir.glob("*.php")}
    en_files = {p.name for p in en_dir.glob("*.php")}
    if ar_files != en_files:
        if ar_files - en_files:
            errors.append("backend language files missing in en: " + ", ".join(sorted(ar_files - en_files)))
        if en_files - ar_files:
            errors.append("backend language files missing in ar: " + ", ".join(sorted(en_files - ar_files)))

    for name in sorted(ar_files & en_files):
        try:
            ar_keys = flatten_php_keys(ar_dir / name)
            en_keys = flatten_php_keys(en_dir / name)
        except RuntimeError as exc:
            errors.append(str(exc))
            continue
        if ar_keys is None or en_keys is None:
            continue
        if ar_keys != en_keys:
            missing_en = sorted(ar_keys - en_keys)
            missing_ar = sorted(en_keys - ar_keys)
            if missing_en:
                errors.append(f"backend/lang/{name}: keys missing in en: {', '.join(missing_en[:20])}")
            if missing_ar:
                errors.append(f"backend/lang/{name}: keys missing in ar: {', '.join(missing_ar[:20])}")


def parse_added_lines(base: str, head: str) -> list[tuple[str, int, str]]:
    if head == "WORKTREE":
        patch = run(
            "git", "diff", "--unified=0", "--no-color", base, "--",
            "apps", "backend/resources/views", "backend/lang",
        )
    else:
        patch = run(
            "git", "diff", "--unified=0", "--no-color", base, head, "--",
            "apps", "backend/resources/views", "backend/lang",
        )
    result: list[tuple[str, int, str]] = []
    current = ""
    new_line = 0

    for raw in patch.splitlines():
        if raw.startswith("+++ b/"):
            current = raw[6:]
            continue
        if raw.startswith("@@"):
            match = re.search(r"\+(\d+)(?:,(\d+))?", raw)
            if match:
                new_line = int(match.group(1))
            continue
        if raw.startswith("+") and not raw.startswith("+++"):
            result.append((current, new_line, raw[1:]))
            new_line += 1
        elif raw.startswith("-") and not raw.startswith("---"):
            continue
        else:
            if current and raw and not raw.startswith("\\"):
                new_line += 1
    if head == "WORKTREE":
        untracked = run(
            "git", "ls-files", "--others", "--exclude-standard", "--",
            "apps", "backend/resources/views", "backend/lang",
        )
        for relative in (line.strip() for line in untracked.splitlines()):
            if not relative:
                continue
            file_path = ROOT / relative
            if not file_path.is_file():
                continue
            try:
                text = file_path.read_text(encoding="utf-8")
            except UnicodeDecodeError:
                continue
            result.extend((relative, index, line) for index, line in enumerate(text.splitlines(), start=1))

    return result


def is_ui_dart(path: str) -> bool:
    if not re.match(r"^apps/(customer_app|driver_app|van_app)/lib/.*\.dart$", path):
        return False
    if "/test/" in path:
        return False
    non_ui_segments = (
        "/core/api/", "/core/config/", "/core/auth/", "/core/push/", "/core/diagnostics/",
        "/models/", "/repositories/", "/repository/", "/data/",
    )
    return not any(segment in path for segment in non_ui_segments)


def line_has_translation_usage(line: str) -> bool:
    markers = (
        ".tr(", "__(", "trans(", "translate(", "localized", "localised", "_text(",
        "name_ar", "name_en", "label_ar", "label_en", "title_ar", "title_en",
        "description_ar", "description_en",
    )
    return any(marker in line for marker in markers)


def scan_added_lines(
    added: Iterable[tuple[str, int, str]],
    errors: list[str],
) -> None:
    dart_ranges: dict[str, dict[str, tuple[int, int]]] = {}

    for path, lineno, line in added:
        if not path:
            continue
        if ALLOW_MARKER in line:
            continue

        if is_ui_dart(path):
            for pattern in VISIBLE_DART_LITERAL_PATTERNS:
                for match in pattern.finditer(line):
                    value = match.group("text").strip()
                    if value and not looks_like_technical_literal(value):
                        errors.append(
                            f"{path}:{lineno}: raw user-facing literal {value!r}; "
                            "use the locale translation catalog/context.tr()"
                        )
            if DYNAMIC_ENUM_PATTERN.search(line) and not line_has_translation_usage(line):
                errors.append(
                    f"{path}:{lineno}: enum/status/type/channel/payment/role/unit data is rendered directly; "
                    "map it through localization before display"
                )
            if "Text(" in line and DIRECT_LOCALE_FIELD_PATTERN.search(line):
                lower = line.lower()
                if "localized" not in lower and "locale" not in lower:
                    errors.append(
                        f"{path}:{lineno}: a language-specific data field is rendered directly; "
                        "select Arabic/English through the active-locale resolver"
                    )

        if path.endswith(".blade.php") and path.startswith("backend/resources/views/"):
            if blade_line_is_inside_nonvisible_block(path, lineno):
                continue

            if re.search(r"\$ar\s*\?\s*['\"]", line):
                errors.append(
                    f"{path}:{lineno}: inline ar/en literal branch detected; "
                    "move user-facing wording to backend/lang and use __()"
                )

            for attr in re.finditer(
                r"\b(?:placeholder|title|aria-label)\s*=\s*(['\"])(?P<text>.*?)(?<!\\)\1",
                line,
                flags=re.IGNORECASE,
            ):
                value = attr.group("text").strip()
                if value and "{{" not in value and "@lang" not in value and not looks_like_technical_literal(value):
                    errors.append(
                        f"{path}:{lineno}: raw localized HTML attribute {value!r}; use __()"
                    )

            for text_match in re.finditer(r">\s*(?P<text>[^<>{}@]+?)\s*<", line):
                value = text_match.group("text").strip()
                if value and (LATIN_RE.search(value) or ARABIC_RE.search(value)) and not looks_like_technical_literal(value):
                    errors.append(
                        f"{path}:{lineno}: raw user-facing Blade text {value!r}; use __()"
                    )

            if BLADE_DYNAMIC_ENUM_PATTERN.search(line) and "__(" not in line and "trans(" not in line:
                errors.append(
                    f"{path}:{lineno}: status/type/channel/payment/role/unit is rendered directly; "
                    "use a localized label/translation"
                )
            if "{{" in line and DIRECT_LOCALE_FIELD_PATTERN.search(line):
                lower = line.lower()
                if "localized" not in lower and "locale" not in lower and "app()->getlocale" not in lower:
                    errors.append(
                        f"{path}:{lineno}: a language-specific data field is rendered directly; "
                        "use the active-locale resolver"
                    )

        if path in {
            "apps/customer_app/lib/core/localization/app_translations.dart",
            "apps/driver_app/lib/core/localization/driver_translations.dart",
        }:
            file_path = ROOT / path
            if path not in dart_ranges and file_path.is_file():
                text = file_path.read_text(encoding="utf-8")
                try:
                    _, ar_range = extract_dart_map(text, "_ar")
                    _, en_range = extract_dart_map(text, "_en")
                    dart_ranges[path] = {"ar": ar_range, "en": en_range}
                except ValueError:
                    dart_ranges[path] = {}

            entry = DART_ENTRY.match(line)
            if entry and dart_ranges.get(path):
                value = entry.group("value")
                ar_range = dart_ranges[path].get("ar")
                en_range = dart_ranges[path].get("en")
                if ar_range and ar_range[0] <= lineno <= ar_range[1]:
                    if LATIN_RE.search(value) and not ARABIC_RE.search(value) and not is_technical_only(value):
                        errors.append(
                            f"{path}:{lineno}: Arabic translation is English-only: {value!r}"
                        )
                if en_range and en_range[0] <= lineno <= en_range[1]:
                    if ARABIC_RE.search(value):
                        errors.append(
                            f"{path}:{lineno}: English translation contains Arabic text: {value!r}"
                        )

        if path.startswith("backend/lang/") and path.endswith(".php"):
            entry = PHP_ENTRY.match(line)
            if entry:
                value = entry.group("value")
                if "/ar/" in path and LATIN_RE.search(value) and not ARABIC_RE.search(value) and not is_technical_only(value):
                    errors.append(f"{path}:{lineno}: Arabic translation is English-only: {value!r}")
                if "/en/" in path and ARABIC_RE.search(value):
                    errors.append(f"{path}:{lineno}: English translation contains Arabic text: {value!r}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    args = parser.parse_args()

    errors: list[str] = []

    check_dart_catalog_parity(errors)
    check_backend_catalog_parity(errors)

    # Van currently uses the locale-aware `_text(en, ar)` resolver rather than a
    # central catalog. Runtime parity is enforced by the Van localization test
    # in the workflow; changed Van UI is still scanned for raw literals/enums.

    try:
        added = parse_added_lines(args.base, args.head)
    except RuntimeError as exc:
        print(exc, file=sys.stderr)
        return 2

    scan_added_lines(added, errors)

    if errors:
        print("FOODEX localization quality gate FAILED:", file=sys.stderr)
        for error in errors:
            print(f" - {error}", file=sys.stderr)
        print(
            "\nRule: Arabic UI must resolve Arabic labels/data and English UI must resolve "
            "English labels/data. Add translation keys to both locales and render statuses/"
            "enums through localization. System-owned text may not silently fall back to the "
            "wrong language. Exceptional technical literals require an inline "
            f"'{ALLOW_MARKER}' justification.",
            file=sys.stderr,
        )
        return 1

    print(
        "FOODEX localization quality gate passed: AR/EN catalog parity is valid and "
        "no new untranslated user-facing UI literals were detected."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
