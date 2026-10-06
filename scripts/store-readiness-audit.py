#!/usr/bin/env python3
"""Deterministic repository-controlled store submission audit for #990."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
META = json.loads((ROOT / "store/submission/metadata.json").read_text())
EXPECTED_VERSION = META["release"]["version"]
EXPECTED_BUILD = META["release"]["build"]
RESULTS: list[dict[str, object]] = []


def text(path: str) -> str:
    return (ROOT / path).read_text()


def check(name: str, condition: bool, detail: str, lane: str | None = None) -> None:
    RESULTS.append({
        "lane": lane or "repository",
        "check": name,
        "state": "PASS" if condition else "BLOCKED",
        "detail": detail,
        "external_manual": False,
    })


def manual(lane: str, detail: str) -> None:
    RESULTS.append({
        "lane": lane,
        "check": "external_manual",
        "state": "WARN",
        "detail": detail,
        "external_manual": True,
    })


def audit_lane(lane: dict[str, object]) -> None:
    app = str(lane["app"])
    platform = str(lane["platform"])
    ident = str(lane["identifier"])
    key = f"{app}-{platform}"
    pubspec = text(f"apps/{app}_app/pubspec.yaml")
    native = text("scripts/configure-mobile-native.py")
    runtime = text("backend/app/Http/Controllers/Api/V1/MobileRuntimeController.php")
    web = text("backend/routes/web.php")

    check("identifier", ident in native, f"{ident} is locked in native configuration", key)
    check(
        "version_build",
        f"version: {EXPECTED_VERSION}+{EXPECTED_BUILD}" in pubspec,
        f"binary metadata is {EXPECTED_VERSION}+{EXPECTED_BUILD}",
        key,
    )
    check(
        "production_api",
        META["production_base_url"] in text(f"apps/{app}_app/lib/core/config/foodex_environment.dart"),
        "production API endpoint is repository-controlled",
        key,
    )
    check(
        "legal_routes",
        all(route in web for route in ["'/privacy'", "'/terms'", "'/support'", "'/account-deletion'"]),
        "public Privacy/Terms/Support/Delete Account routes exist",
        key,
    )
    check(
        "footer_visibility_only",
        "footer_display_mode" in runtime and "published_version" in runtime,
        "runtime exposes visibility separately; client footer code never consumes published_version",
        key,
    )
    if platform == "android":
        config = ROOT / f"apps/{app}_app/android/app/google-services.json"
        package_ok = False
        if config.is_file():
            data = json.loads(config.read_text())
            packages = {
                row.get("client_info", {}).get("android_client_info", {}).get("package_name")
                for row in data.get("client", [])
            }
            package_ok = ident in packages
        check("firebase_android", package_ok, "google-services.json matches production package", key)
        check(
            "store_signing_guard",
            "foodexStoreRelease" in text("scripts/configure-android-store-signing.py")
            and 'getByName("debug")' in text("scripts/configure-android-store-signing.py"),
            "store signing script explicitly rejects debug fallback",
            key,
        )
    else:
        check(
            "ios_identity_and_push",
            "aps-environment" in native and ident in native,
            "iOS bundle identity and production push entitlement are generated",
            key,
        )

    for item in lane.get("external_manual", []):
        manual(key, str(item))


def main() -> int:
    check(
        "customer_deletion_workflow",
        (ROOT / "backend/app/Services/AccountDeletionService.php").is_file()
        and "tokens()->delete()" in text("backend/app/Services/AccountDeletionService.php")
        and "financial_records_retained" in text("backend/app/Services/AccountDeletionService.php"),
        "verified customer deletion/anonymization and token revocation are implemented",
    )
    check(
        "reviewer_secret_protection",
        "'secret_encrypted' => 'encrypted'" in text("backend/app/Models/StoreReviewerAccount.php")
        and "protected $hidden = ['secret_encrypted']" in text("backend/app/Models/StoreReviewerAccount.php"),
        "reviewer secret is encrypted and hidden",
    )
    check(
        "no_store_secret_commits",
        not any(
            p.is_file() and re.search(r"BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY", p.read_text(errors="ignore"))
            for p in ROOT.rglob("*")
            if ".git" not in p.parts
        ),
        "no private-key PEM material is committed",
    )

    for lane in META["lanes"]:
        audit_lane(lane)

    repo_blocked = [r for r in RESULTS if r["state"] == "BLOCKED" and not r["external_manual"]]
    lanes = sorted({str(r["lane"]) for r in RESULTS if r["lane"] != "repository"})
    summary = {}
    for lane in lanes:
        rows = [r for r in RESULTS if r["lane"] == lane]
        state = "BLOCKED" if any(r["state"] == "BLOCKED" for r in rows) else (
            "WARN" if any(r["state"] == "WARN" for r in rows) else "PASS"
        )
        summary[lane] = state

    report = {"summary": summary, "checks": RESULTS}
    output = ROOT / "artifacts/store-readiness"
    output.mkdir(parents=True, exist_ok=True)
    (output / "store-readiness.json").write_text(json.dumps(report, indent=2) + "\n")

    print("FOODEX #990 STORE READINESS")
    for lane, state in summary.items():
        print(f"{lane}: {state}")
    for row in repo_blocked:
        print(f"BLOCKED [{row['lane']}] {row['check']}: {row['detail']}", file=sys.stderr)

    return 1 if repo_blocked else 0


if __name__ == "__main__":
    raise SystemExit(main())
