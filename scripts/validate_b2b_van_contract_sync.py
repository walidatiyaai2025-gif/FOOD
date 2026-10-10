#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path

REQUIRED_VAN_PATHS = (
    "/van/orders:",
    "/van/orders/{order}:",
    "/van/orders/{order}/execution:",
    "/van/orders/{order}/execution/allowed-actions:",
    "/van/orders/{order}/execution/transition:",
    "/van/orders/{order}/execution/proof:",
    "/van/orders/{order}/execution/fail:",
    "/van/orders/{order}/execution/retry:",
    "/van/customers/{type}/{customer}/collection-context:",
    "/van/customers/{type}/{customer}/collect:",
    "/van/wallet:",
    "/van/remittances:",
)

REQUIRED_VAN_ACTIONS = {
    "van.b2b.order.accept",
    "van.b2b.order.pickup",
    "van.b2b.order.out_for_delivery",
    "van.b2b.order.proof",
    "van.b2b.order.delivered",
    "van.b2b.order.fail",
    "van.b2b.order.retry",
    "van.b2b.order.collect",
    "van.b2b.order.receipt",
    "van.b2b.finance.collect",
    "van.b2b.finance.remit",
}


def validate_openapi(text: str) -> list[str]:
    errors: list[str] = []
    if "- name: Van Fulfillment" not in text:
        errors.append("OpenAPI is missing the Van Fulfillment tag.")
    for path in REQUIRED_VAN_PATHS:
        if f"  {path}" not in text:
            errors.append(f"OpenAPI is missing {path}")
    return errors


def validate_visual_workflow(text: str) -> list[str]:
    errors: list[str] = []
    required = (
        "apps/(customer_app|driver_app|van_app)/lib/.*\\.dart$",
        "mobile_ui=true",
        "needs.changes.outputs.mobile_ui == 'true'",
        "uses: ./.github/workflows/mobile-screenshot-capture.yml",
        "backend/resources/views/admin/",
        "dashboard_ui=true",
        "needs.changes.outputs.dashboard_ui == 'true'",
        "uses: ./.github/workflows/ui-visual-qa.yml",
    )
    for marker in required:
        if marker not in text:
            errors.append(f"Required CI visual evidence contract is missing: {marker}")
    return errors


def validate_release_lineage(contract: str, validator: str, distribution: str) -> list[str]:
    errors: list[str] = []
    for marker in ("LATEST_RELEASE.json", "BUILD_INFO.json", "source commit"):
        if marker not in contract:
            errors.append(f"Release artifact contract is missing lineage marker: {marker}")
    if "source_commit" not in validator:
        errors.append("Release artifact validator does not enforce source_commit.")
    if "validate_release_artifact_contract.py" not in distribution:
        errors.append("Trial distribution does not invoke the release artifact lineage validator.")
    return errors


def validate(root: Path) -> list[str]:
    errors: list[str] = []

    openapi = (root / "docs/api/openapi.yaml").read_text(encoding="utf-8")
    errors.extend(validate_openapi(openapi))

    order_doc = (root / "docs/workflows/ORDER_LIFECYCLE.md").read_text(encoding="utf-8")
    driver_doc = (root / "docs/workflows/DRIVER_WORKFLOW.md").read_text(encoding="utf-8")
    van_doc = (root / "docs/workflows/VAN_WORKFLOW.md").read_text(encoding="utf-8")

    for marker in ("B2B / Wholesale | Van only", "awaiting_dispatch", "no Driver fallback"):
        if marker not in order_doc:
            errors.append(f"ORDER_LIFECYCLE is missing locked fulfillment marker: {marker}")
    for marker in ("B2C / Retail only", "/driver/b2b/**", "rejected"):
        if marker not in driver_doc:
            errors.append(f"DRIVER_WORKFLOW is missing cutover marker: {marker}")
    for marker in ("B2B / Wholesale only", "no Driver fallback", "/api/v1/van/orders", "/api/v1/van/remittances"):
        if marker not in van_doc:
            errors.append(f"VAN_WORKFLOW is missing authoritative marker: {marker}")

    registry = json.loads(
        (root / "docs/execution/UI_ROUTE_AUTHORITY.json").read_text(encoding="utf-8")
    )
    driver_functions = registry["surfaces"]["driver"].get("functions", [])
    for item in driver_functions:
        if str(item.get("id", "")).startswith("driver.b2b") or "/driver/b2b/" in str(item.get("route", "")):
            errors.append("Driver route authority exposes a forbidden B2B function.")

    van_actions = {
        item.get("id")
        for item in registry["surfaces"]["van"].get("fulfillment_actions", [])
        if isinstance(item, dict)
    }
    for action in sorted(REQUIRED_VAN_ACTIONS - van_actions):
        errors.append(f"Van route authority is missing fulfillment action: {action}")

    inspector_test = (
        root / "backend/tests/Feature/MobileSystemInspectorEventTest.php"
    ).read_text(encoding="utf-8")
    for marker in (
        "test_b2b_van_fulfillment_failure_is_actionable_in_system_inspector",
        "van_fulfillment_failure",
        "cid-van-b2b-1206",
    ):
        if marker not in inspector_test:
            errors.append(f"System Inspector B2B Van evidence is missing: {marker}")

    required_ci = (root / ".github/workflows/required-ci-gate.yml").read_text(
        encoding="utf-8"
    )
    errors.extend(validate_visual_workflow(required_ci))

    release_contract = (
        root / "docs/release/RELEASE_ARTIFACT_CONTRACT.md"
    ).read_text(encoding="utf-8")
    release_validator = (
        root / "scripts/validate_release_artifact_contract.py"
    ).read_text(encoding="utf-8")
    trial_distribution = (
        root / ".github/workflows/trial-distribution.yml"
    ).read_text(encoding="utf-8")
    errors.extend(
        validate_release_lineage(release_contract, release_validator, trial_distribution)
    )

    return errors


def main() -> int:
    errors = validate(Path(".").resolve())
    if errors:
        for error in errors:
            print(f"ERROR: {error}")
        return 1
    print("B2B Van W15 contract synchronization validated.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
