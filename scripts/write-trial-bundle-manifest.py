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
    parser.add_argument("--mobile-build-number", required=True, type=int)
    parser.add_argument("--customer-version")
    parser.add_argument("--customer-build-number", type=int)
    parser.add_argument("--customer-source-commit")
    parser.add_argument("--customer-tree-sha")
    parser.add_argument("--customer-lineage", default="synchronized")
    parser.add_argument("--api-base-url", required=True)
    parser.add_argument("--customer", type=Path, required=True)
    parser.add_argument("--driver", type=Path, required=True)
    parser.add_argument("--van", type=Path, required=True)
    parser.add_argument("--setup", type=Path, required=True)
    parser.add_argument("--customer-firebase", default="false")
    parser.add_argument("--driver-firebase", default="false")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    customer_version = args.customer_version or args.version
    customer_build_number = args.customer_build_number or args.mobile_build_number
    customer_source_commit = args.customer_source_commit or args.source_commit
    if args.customer_lineage == "carried_forward":
        if not args.customer_tree_sha:
            raise SystemExit("--customer-tree-sha is required for carried_forward Customer lineage")
        if customer_source_commit == args.source_commit:
            raise SystemExit(
                "carried_forward Customer lineage must identify its frozen source commit"
            )

    for path in (args.customer, args.driver, args.van, args.setup):
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
        "release_lineage": {
            "customer": args.customer_lineage,
            "driver": "synchronized",
            "van": "synchronized",
        },
        "customer": {
            **file_entry(args.customer),
            "version": customer_version,
            "build_number": customer_build_number,
            "release_version": args.version,
            "lineage_mode": args.customer_lineage,
            "source_commit": customer_source_commit,
            **({"tree_sha": args.customer_tree_sha} if args.customer_tree_sha else {}),
            "application_id": "com.fiftysolution.foodex.customer",
            "firebase_configured": parse_bool(args.customer_firebase),
        },
        "driver": {
            **file_entry(args.driver),
            "version": args.version,
            "build_number": args.mobile_build_number,
            "release_version": args.version,
            "lineage_mode": "synchronized",
            "source_commit": args.source_commit,
            "application_id": "com.fiftysolution.foodex.driver",
            "firebase_configured": parse_bool(args.driver_firebase),
        },
        "van": {
            **file_entry(args.van),
            "version": args.version,
            "build_number": args.mobile_build_number,
            "release_version": args.version,
            "lineage_mode": "synchronized",
            "source_commit": args.source_commit,
            "application_id": "com.foodex.van",
            "firebase_configured": True,
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
