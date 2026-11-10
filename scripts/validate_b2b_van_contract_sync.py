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


def validate_no_screenshot_workflow(text: str, ci_map: dict) -> list[str]:
    """Retired screenshot CI jobs must not be mandatory."""
    errors: list[str] = []
    for name in (
        "screenshot-evidence.yml", "mobile-screenshot-capture.yml",
        "ui-visual-qa.yml", "app-preview-visual-parity.yml",
        "field-operations-runtime-evidence.yml",
    ):
        if f"uses: ./.github/workflows/{name}" in text:
            errors.append(f"Retired screenshot workflow is still required: {name}")
    if "name: required-ci-gate" not in text:
        errors.append("Required CI aggregator is missing")
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

    # Owner-locked V2 actor policy: B2B is Van-only, even for manual dispatch.
    # Legacy manual B2B Driver markers/routes are a regression, not evidence.
    for marker in ("Van only, including manual dispatch", "awaiting_dispatch", "No Driver fallback"):
        if marker not in order_doc:
            errors.append(f"ORDER_LIFECYCLE is missing Van-only B2B marker: {marker}")
    for marker in ("only for B2C / Retail", "B2B / Wholesale fulfillment is Van-only", "Historical B2B Driver"):
        if marker not in driver_doc:
            errors.append(f"DRIVER_WORKFLOW is missing Retail-only Driver marker: {marker}")
    for marker in ("B2B routing and manual dispatch are **Van-only**", "awaiting_dispatch",
                   "/api/v1/van/orders", "/api/v1/van/remittances"):
        if marker not in van_doc:
            errors.append(f"VAN_WORKFLOW is missing Van-only marker: {marker}")

    registry = json.loads(
        (root / "docs/execution/UI_ROUTE_AUTHORITY.json").read_text(encoding="utf-8")
    )
    driver_surface = registry["surfaces"]["driver"]
    driver_functions = driver_surface.get("functions", [])
    by_id = {str(item.get("id", "")): item for item in driver_functions if isinstance(item, dict)}
    for route_id in ("driver.b2c.home", "driver.b2c.deliveries", "driver.b2c.notifications"):
        if route_id not in by_id:
            errors.append(f"Driver route authority is missing Retail route: {route_id}")
    for route_id in ("driver.b2b.home", "driver.b2b.deliveries", "driver.b2b.notifications"):
        if route_id in by_id:
            errors.append(f"Legacy B2B Driver route remains active: {route_id}")
    legacy_denied = {str(item.get("id", "")) for item in driver_surface.get("legacy_routes_denied", [])
                     if isinstance(item, dict)}
    for route_id in ("driver.b2b.home", "driver.b2b.deliveries", "driver.b2b.notifications"):
        if route_id not in legacy_denied:
            errors.append(f"Driver route authority must mark old B2B link denied: {route_id}")
    if "B2C-only" not in str(driver_surface.get("fulfillment_policy", "")):
        errors.append("Driver route authority must state Retail B2C-only execution.")

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
    ci_map = json.loads((root / ".ci/ci-map.json").read_text(encoding="utf-8"))
    errors.extend(validate_no_screenshot_workflow(required_ci, ci_map))
    for name in (
        "screenshot-evidence.yml", "mobile-screenshot-capture.yml",
        "ui-visual-qa.yml", "app-preview-visual-parity.yml",
        "field-operations-runtime-evidence.yml",
    ):
        if (root / ".github/workflows" / name).exists():
            errors.append(f"Retired screenshot workflow was reintroduced: {name}")

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
    print("B2B Van-only / B2C Driver-only contract synchronization validated.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
