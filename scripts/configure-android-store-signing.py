#!/usr/bin/env python3
"""Configure generated Flutter Android project for explicit release signing.

Never falls back to Flutter's debug signing. Secrets are read only from the
process environment and are not written to repository source.
"""

from __future__ import annotations

import argparse
import os
import re
from pathlib import Path

REQUIRED = (
    "FOODEX_ANDROID_KEYSTORE_PATH",
    "FOODEX_ANDROID_KEYSTORE_PASSWORD",
    "FOODEX_ANDROID_KEY_ALIAS",
    "FOODEX_ANDROID_KEY_PASSWORD",
)


def require_environment() -> dict[str, str]:
    values = {name: os.environ.get(name, "") for name in REQUIRED}
    missing = [name for name, value in values.items() if not value]
    if missing:
        raise SystemExit("Missing explicit store signing inputs: " + ", ".join(missing))
    path = Path(values["FOODEX_ANDROID_KEYSTORE_PATH"])
    if not path.is_file():
        raise SystemExit("FOODEX Android keystore path does not exist")
    return values


def patch_kotlin(path: Path) -> None:
    text = path.read_text()
    if 'create("foodexStoreRelease")' not in text:
        android_marker = "android {\n"
        signing = """android {
    signingConfigs {
        create("foodexStoreRelease") {
            storeFile = file(System.getenv("FOODEX_ANDROID_KEYSTORE_PATH")
                ?: error("FOODEX_ANDROID_KEYSTORE_PATH is required"))
            storePassword = System.getenv("FOODEX_ANDROID_KEYSTORE_PASSWORD")
                ?: error("FOODEX_ANDROID_KEYSTORE_PASSWORD is required")
            keyAlias = System.getenv("FOODEX_ANDROID_KEY_ALIAS")
                ?: error("FOODEX_ANDROID_KEY_ALIAS is required")
            keyPassword = System.getenv("FOODEX_ANDROID_KEY_PASSWORD")
                ?: error("FOODEX_ANDROID_KEY_PASSWORD is required")
        }
    }
"""
        if android_marker not in text:
            raise SystemExit("Generated Kotlin Android block not found")
        text = text.replace(android_marker, signing, 1)

    text = re.sub(
        r'signingConfig\s*=\s*signingConfigs\.getByName\(["\']debug["\']\)',
        'signingConfig = signingConfigs.getByName("foodexStoreRelease")',
        text,
    )
    if 'signingConfig = signingConfigs.getByName("foodexStoreRelease")' not in text:
        release = 'release {\n'
        if release not in text:
            raise SystemExit("Generated Kotlin release build type not found")
        text = text.replace(
            release,
            release + '            signingConfig = signingConfigs.getByName("foodexStoreRelease")\n',
            1,
        )
    path.write_text(text)


def patch_groovy(path: Path) -> None:
    text = path.read_text()
    if "foodexStoreRelease" not in text:
        android_marker = "android {\n"
        signing = """android {
    signingConfigs {
        foodexStoreRelease {
            storeFile file(System.getenv("FOODEX_ANDROID_KEYSTORE_PATH"))
            storePassword System.getenv("FOODEX_ANDROID_KEYSTORE_PASSWORD")
            keyAlias System.getenv("FOODEX_ANDROID_KEY_ALIAS")
            keyPassword System.getenv("FOODEX_ANDROID_KEY_PASSWORD")
        }
    }
"""
        if android_marker not in text:
            raise SystemExit("Generated Groovy Android block not found")
        text = text.replace(android_marker, signing, 1)

    text = re.sub(
        r'signingConfig\s+signingConfigs\.debug',
        'signingConfig signingConfigs.foodexStoreRelease',
        text,
    )
    if 'signingConfig signingConfigs.foodexStoreRelease' not in text:
        release = 'release {\n'
        if release not in text:
            raise SystemExit("Generated Groovy release build type not found")
        text = text.replace(
            release,
            release + '            signingConfig signingConfigs.foodexStoreRelease\n',
            1,
        )
    path.write_text(text)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--app-dir", type=Path, required=True)
    args = parser.parse_args()

    require_environment()
    app = args.app_dir / "android" / "app"
    kotlin = app / "build.gradle.kts"
    groovy = app / "build.gradle"
    if kotlin.is_file():
        patch_kotlin(kotlin)
    elif groovy.is_file():
        patch_groovy(groovy)
    else:
        raise SystemExit("Generated Android app Gradle file not found")

    rendered = (kotlin if kotlin.is_file() else groovy).read_text()
    if 'signingConfigs.getByName("debug")' in rendered or "signingConfigs.debug" in rendered:
        raise SystemExit("Store release configuration still references debug signing")

    print("Explicit FOODEX release signing configured; secret values were not persisted.")


if __name__ == "__main__":
    main()
