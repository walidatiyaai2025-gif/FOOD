#!/usr/bin/env python3
"""Package CI-only release-mode artifacts. Never produces a production release."""

import argparse
import hashlib
import json
import re
import shutil
from pathlib import Path


def package(root, app, platform, source, output, commit, build_number):
    root, source, output = Path(root), Path(source), Path(output)
    if app not in ("customer", "driver") or platform not in ("android", "ios"):
        raise ValueError("Unsupported app/platform")
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("An exact source commit SHA is required")
    if not re.fullmatch(r"[1-9][0-9]*", str(build_number)):
        raise ValueError("Build number must be positive")
    if not source.is_file() or source.stat().st_size == 0:
        raise ValueError("Build artifact is missing or empty")
    expected_suffix = ".apk" if platform == "android" else ".zip"
    if source.suffix != expected_suffix:
        raise ValueError("Artifact does not match platform")
    pubspec = (root / f"apps/{app}_app/pubspec.yaml").read_text()
    match = re.search(r"^version:\s*([0-9]+\.[0-9]+\.[0-9]+)(?:\+[0-9]+)?\s*$", pubspec, re.M)
    if not match:
        raise ValueError("Explicit numeric mobile version is required")
    version = match[1]
    filename = f"foodex-{app}-{platform}-{version}+{build_number}-{commit[:12]}-validation{expected_suffix}"
    output.mkdir(parents=True, exist_ok=True)
    destination = output / filename
    shutil.copyfile(source, destination)
    hasher = hashlib.sha256()
    with destination.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            hasher.update(chunk)
    digest = hasher.hexdigest()
    manifest = {
        "schema_version": 1,
        "app": app,
        "platform": platform,
        "mobile_version": version,
        "build_number": str(build_number),
        "platform_version": (root / "VERSION").read_text().strip(),
        "source_commit": commit,
        "build_mode": "release",
        "purpose": "ci-validation-only",
        "production_ready": False,
        "api_base_url": "https://foodex-validation.invalid",
        "native_identity": "generated-template-not-production",
        "signing": "flutter-template-debug-key" if platform == "android" else "none",
        "artifact": filename,
        "bytes": destination.stat().st_size,
        "sha256": digest,
    }
    (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    (output / "SHA256SUMS").write_text(f"{digest}  {filename}\n")
    (output / "README.txt").write_text(
        "FOODEX release-mode CI validation only.\n"
        "The API endpoint is intentionally non-resolving; business use requires a configured build.\n"
        "Android uses the generated template debug key; iOS is an unsigned .app archive, not an installable IPA.\n"
        "Native identity/icons and production signing are not certified by this artifact.\n"
        "Do not distribute as a production release or submit to stores. See manifest.json and SHA256SUMS.\n"
    )
    return manifest


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--app", required=True, choices=("customer", "driver"))
    parser.add_argument("--platform", required=True, choices=("android", "ios"))
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--build-number", required=True)
    args = parser.parse_args()
    result = package(Path(__file__).resolve().parents[1], args.app, args.platform,
                     args.input, args.output, args.commit, args.build_number)
    print(json.dumps(result, indent=2))
