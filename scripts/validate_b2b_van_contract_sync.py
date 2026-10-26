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


def validate_visual_workflow(text: str, ci_map: dict) -> list[str]:
    errors: list[str] = []

    required_workflow = (
        "mobile_ui: ${{ steps.filter.outputs.mobile_ui }}",
        "needs.changes.outputs.mobile_ui == 'true'",
        "uses: ./.github/workflows/mobile-screenshot-capture.yml",
        "dashboard_ui: ${{ steps.filter.outputs.dashboard_ui }}",
        "needs.changes.outputs.dashboard_ui == 'true'",
        "uses: ./.github/workflows/ui-visual-qa.yml",
    )
    for marker in required_workflow:
        if marker not in text:
            errors.append(f"Required CI visual evidence contract is missing: {marker}")

    areas = ci_map.get("areas", {})
    required_routes = {
        "mobile_ui": "apps/(customer_app|driver_app|van_app)/lib/.*\\.dart$",
        "dashboard_ui": "backend/resources/views/admin/",
    }
    for area, marker in required_routes.items():
        path_regex = str(areas.get(area, {}).get("path_regex", ""))
        if marker not in path_regex:
            errors.append(
                f"CI map visual evidence route is missing {area}: {marker}"
            )

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

    for marker in ("Van for automatic routing", "authorized manual Driver or Van dispatch", "awaiting_dispatch", "No automatic Driver fallback"):
        if marker not in order_doc:
            errors.append(f"ORDER_LIFECYCLE is missing B2B fulfillment marker: {marker}")
    for marker in ("explicitly assigned B2B / Wholesale Drivers", "manual Order Operations dispatch", "No automatic B2B Driver fallback"):
        if marker not in driver_doc:
            errors.append(f"DRIVER_WORKFLOW is missing manual B2B Driver marker: {marker}")
    for marker in ("automatic routing runtime", "Automatic B2B routing never falls back to a Driver", "/api/v1/van/orders", "/api/v1/van/remittances"):
        if marker not in van_doc:
            errors.append(f"VAN_WORKFLOW is missing authoritative marker: {marker}")

    registry = json.loads(
        (root / "docs/execution/UI_ROUTE_AUTHORITY.json").read_text(encoding="utf-8")
    )
    driver_surface = registry["surfaces"]["driver"]
    driver_functions = driver_surface.get("functions", [])
    required_manual_driver_routes = {
        "driver.b2b.home": "/driver/b2b/home",
        "driver.b2b.deliveries": "/driver/b2b/deliveries",
        "driver.b2b.notifications": "/driver/b2b/notifications",
    }
    by_id = {str(item.get("id", "")): item for item in driver_functions if isinstance(item, dict)}
    for route_id, route in required_manual_driver_routes.items():
        item = by_id.get(route_id)
        if item is None or str(item.get("route", "")) != route or item.get("scope") != "manual_dispatch_only":
            errors.append(f"Driver route authority is missing scoped manual B2B route: {route_id}")
    if "Van-first" not in str(driver_surface.get("fulfillment_policy", "")):
        errors.append("Driver route authority must preserve Van-first automatic B2B routing.")

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
    errors.extend(validate_visual_workflow(required_ci, ci_map))

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
    print("B2B Van-first + manual Driver/Van dispatch contract synchronization validated.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
