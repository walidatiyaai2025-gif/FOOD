#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def parse_bool(value: str) -> bool:
    return value.strip().lower() in {"1", "true", "yes", "on"}


def file_entry(path: Path) -> dict[str, object]:
    return {
        "file": path.name,
        "bytes": path.stat().st_size,
        "sha256": sha256(path),
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--version", required=True)
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--run-number", required=True)
    parser.add_argument("--api-base-url", required=True)
    parser.add_argument("--customer", type=Path, required=True)
    parser.add_argument("--driver", type=Path, required=True)
    parser.add_argument("--setup", type=Path, required=True)
    parser.add_argument("--customer-firebase", default="false")
    parser.add_argument("--driver-firebase", default="false")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    for path in (args.customer, args.driver, args.setup):
        if not path.is_file() or path.stat().st_size < 1:
            raise SystemExit(f"Required distribution file is missing or empty: {path}")

    payload = {
        "schema_version": 1,
        "version": args.version,
        "source_commit": args.source_commit,
        "github_run_number": args.run_number,
        "api_base_url": args.api_base_url.rstrip("/"),
        "android_signing": "flutter-template-debug-key-test-only",
        "production_store_ready": False,
        "customer": {
            **file_entry(args.customer),
            "application_id": "com.fiftysolution.foodex.customer",
            "firebase_configured": parse_bool(args.customer_firebase),
        },
        "driver": {
            **file_entry(args.driver),
            "application_id": "com.fiftysolution.foodex.driver",
            "firebase_configured": parse_bool(args.driver_firebase),
        },
        "laravel_setup": {
            **file_entry(args.setup),
            "installer_path": "/install",
            "document_root": "backend/public",
        },
    }

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


if __name__ == "__main__":
    main()
