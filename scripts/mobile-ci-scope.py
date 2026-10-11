#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def git(*args: str) -> str:
    return subprocess.check_output(["git", "-C", str(ROOT), *args], text=True)


def changed_files(base: str, head: str) -> list[str]:
    return [p for p in git("diff", "--name-only", base, head).splitlines() if p]


def resolve(app: str, paths: list[str]) -> dict[str, bool]:
    prefix = f"apps/{app}_app/"
    app_paths = [p[len(prefix):] for p in paths if p.startswith(prefix)]

    shared_flutter = any(
        re.match(r"^packages/(design_tokens|foodex_visualization|foodex_formatting)/", p)
        for p in paths
    )
    shared_native = any(
        p in {
            "scripts/configure-mobile-native.py",
            "scripts/verify-mobile-runtime-endpoint.py",
            ".github/workflows/mobile-build-validation.yml",
        }
        for p in paths
    )

    runtime_or_assets = any(
        re.match(r"^(lib/|assets/)", p) for p in app_paths
    )
    android_native = any(p.startswith("android/") for p in app_paths)
    ios_native = any(p.startswith("ios/") for p in app_paths)
    web_native = any(p.startswith("web/") for p in app_paths)
    dependency_change = any(p in {"pubspec.yaml", "pubspec.lock"} for p in app_paths)

    android = runtime_or_assets or android_native or dependency_change or shared_flutter or shared_native
    ios = app in {"customer", "driver"} and (
        ios_native or dependency_change or shared_flutter or shared_native
    )
    web_preview = app in {"customer", "driver"} and (
        web_native
        or dependency_change
        or shared_flutter
        or any(
            p == "lib/preview_main.dart" or p.startswith("lib/core/preview/")
            for p in app_paths
        )
    )

    return {
        "android": android,
        "ios": ios,
        "web_preview": web_preview,
        "store": android_native or ios_native or dependency_change or shared_native,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--app", required=True, choices=("customer", "driver", "van"))
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--github-output")
    parser.add_argument("--json")
    args = parser.parse_args()

    plan = resolve(args.app, changed_files(args.base, args.head))

    if args.github_output:
        with Path(args.github_output).open("a", encoding="utf-8") as fh:
            for key, value in plan.items():
                fh.write(f"{key}={'true' if value else 'false'}\n")

    if args.json:
        target = Path(args.json)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(plan, indent=2) + "\n", encoding="utf-8")

    print(json.dumps(plan, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
