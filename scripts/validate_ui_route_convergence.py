#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any


REGISTRY = Path("docs/execution/UI_ROUTE_AUTHORITY.json")


def _read(root: Path, relative: str) -> str:
    return (root / relative).read_text(encoding="utf-8")


def validate(root: Path, registry: dict[str, Any]) -> list[str]:
    errors: list[str] = []

    if registry.get("schema_version") != 1:
        errors.append("UI route registry schema_version must be 1.")
    if registry.get("issue") != 1100:
        errors.append("UI route registry must remain owned by issue #1100.")

    surfaces = registry.get("surfaces")
    if not isinstance(surfaces, dict):
        return errors + ["UI route registry surfaces must be an object."]

    required_surfaces = {"customer", "driver", "van", "dashboard"}
    missing_surfaces = sorted(required_surfaces - set(surfaces))
    if missing_surfaces:
        errors.append(f"Missing UI route surfaces: {', '.join(missing_surfaces)}")

    ids: list[str] = []
    for surface in surfaces.values():
        if not isinstance(surface, dict):
            continue
        for collection in ("functions", "compatibility", "fulfillment_actions"):
            for item in surface.get(collection, []):
                if isinstance(item, dict) and isinstance(item.get("id"), str):
                    ids.append(item["id"])
    duplicates = sorted({item for item in ids if ids.count(item) > 1})
    if duplicates:
        errors.append(f"Duplicate UI function ids: {', '.join(duplicates)}")

    for name, surface in surfaces.items():
        if not isinstance(surface, dict):
            errors.append(f"Surface {name} must be an object.")
            continue
        for key in ("authority_source", "router", "evidence_test", "authority_test",
                    "api_authority_source", "shell", "sidebar",
                    "convergence_test", "responsive_test"):
            relative = surface.get(key)
            if relative is None:
                continue
            if not (root / relative).is_file():
                errors.append(f"{name}.{key} is missing: {relative}")

        for relative in surface.get("localization_sources", []):
            if not (root / relative).exists():
                errors.append(f"{name} localization source is missing: {relative}")

        for relative in surface.get("forbidden_files", []):
            if (root / relative).exists():
                errors.append(f"Forbidden legacy UI file is reachable in tree: {relative}")

        for guard in surface.get("forbidden_markers", []):
            if not isinstance(guard, dict):
                continue
            relative = guard.get("file")
            marker = guard.get("marker")
            if not isinstance(relative, str) or not isinstance(marker, str):
                errors.append(f"Malformed forbidden marker guard in {name}.")
                continue
            path = root / relative
            if not path.is_file():
                errors.append(f"Guarded file is missing: {relative}")
                continue
            if marker in path.read_text(encoding="utf-8"):
                errors.append(f"Forbidden legacy marker {marker!r} found in {relative}")

    for relative in registry.get("design_systems", []):
        if not (root / relative).is_file():
            errors.append(f"Shared design-system contract is missing: {relative}")

    customer = surfaces.get("customer", {})
    if isinstance(customer, dict):
        authority_path = customer.get("authority_source")
        router_path = customer.get("router")
        if isinstance(authority_path, str) and (root / authority_path).is_file():
            authority = _read(root, authority_path)
            expected = {
                "CustomerRoutePaths.retailHome": "CustomerRouteAuthority.retailJourney",
                "CustomerRoutePaths.retailProductDetails": "CustomerRouteAuthority.retailJourney",
                "CustomerRoutePaths.b2bOrders": "CustomerRouteAuthority.multiStore",
                "CustomerRoutePaths.b2bOrderDetails": "CustomerRouteAuthority.multiStore",
                "CustomerRoutePaths.b2bDashboard": "CustomerRouteAuthority.b2bJourney",
            }
            for route_marker, authority_marker in expected.items():
                if route_marker not in authority or authority_marker not in authority:
                    errors.append(
                        f"Customer authority registry is missing {route_marker} -> {authority_marker}."
                    )
        if isinstance(router_path, str) and (root / router_path).is_file():
            router = _read(root, router_path)
            if "customerRouteAuthorityFor(" not in router:
                errors.append("Customer router does not consume the authoritative route resolver.")
            if "CustomerRouteAuthority.multiStore" not in router:
                errors.append("Customer router does not dispatch the multi-store authority.")
            if "CustomerRouteAuthority.b2bJourney" not in router:
                errors.append("Customer router does not dispatch the B2B account authority.")

    driver = surfaces.get("driver", {})
    if isinstance(driver, dict):
        for function in driver.get("functions", []):
            if not isinstance(function, dict):
                continue
            function_id = str(function.get("id", ""))
            route = str(function.get("route", ""))
            if function_id.startswith("driver.b2b") or "/driver/b2b/" in route:
                errors.append(
                    f"Driver route registry still exposes forbidden B2B function: {function_id or route}"
                )
        source = driver.get("authority_source")
        if isinstance(source, str) and (root / source).is_file():
            navigation = _read(root, source)
            for marker in (
                "DriverRoutes.b2cHome",
                "DriverRoutes.b2cDeliveries",
                "DriverRoutes.b2cNotifications",
                "DriverRoutes.b2cWallet",
                "DriverJourneyRuntimePage(",
                "DriverNotificationPage(",
                "DriverWalletPage(",
            ):
                if marker not in navigation:
                    errors.append(f"Driver authority is missing production marker: {marker}")
            for marker in ("DriverRoutes.b2b", "/driver/b2b/"):
                if marker in navigation:
                    errors.append(
                        f"Driver authority still exposes forbidden B2B runtime marker: {marker}"
                    )

    van = surfaces.get("van", {})
    if isinstance(van, dict):
        source = van.get("authority_source")
        router = van.get("router")
        screens = van.get("production_screens", [])
        if isinstance(source, str) and (root / source).is_file():
            inventory = _read(root, source)
            for screen in screens:
                marker = f"VanScreenId.{screen},"
                if marker not in inventory:
                    errors.append(f"Van production inventory is missing {screen}.")
        if isinstance(router, str) and (root / router).is_file():
            foundation = _read(root, router)
            for screen in screens:
                if screen == "login":
                    marker = "case VanScreenId.login:"
                else:
                    marker = f"case VanScreenId.{screen}:"
                if marker not in foundation:
                    errors.append(f"Van production router is missing {screen}.")

    if isinstance(van, dict):
        actions = van.get("fulfillment_actions", [])
        required_action_ids = {
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
        action_ids = {
            item.get("id")
            for item in actions
            if isinstance(item, dict) and isinstance(item.get("id"), str)
        }
        for action_id in sorted(required_action_ids - action_ids):
            errors.append(f"Van fulfillment action registry is missing {action_id}.")
        for action in actions:
            if not isinstance(action, dict):
                errors.append("Malformed Van fulfillment action entry.")
                continue
            action_id = action.get("id")
            source_path = action.get("source")
            marker = action.get("marker")
            if not all(isinstance(value, str) and value for value in (action_id, source_path, marker)):
                errors.append("Malformed Van fulfillment action contract.")
                continue
            path = root / source_path
            if not path.is_file():
                errors.append(f"Van fulfillment action source is missing: {source_path}")
                continue
            if marker not in _read(root, source_path):
                errors.append(
                    f"Van fulfillment action {action_id} is missing source marker: {marker}"
                )

    evidence_expectations = (
        ("customer", "FoodexCustomerApp("),
        ("driver", "FoodexDriverApp("),
        ("van", "FoodexVanApp("),
    )
    for surface_name, app_marker in evidence_expectations:
        surface = surfaces.get(surface_name, {})
        if not isinstance(surface, dict):
            continue
        relative = surface.get("evidence_test")
        if not isinstance(relative, str) or not (root / relative).is_file():
            continue
        evidence = _read(root, relative)
        if app_marker not in evidence:
            errors.append(f"{surface_name} evidence does not boot the production app.")
        for locale_marker in ("Locale('ar')", "Locale('en')"):
            if locale_marker not in evidence:
                errors.append(
                    f"{surface_name} evidence is missing {locale_marker} parity coverage."
                )

    return errors


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", default="validate", choices=["validate"])
    parser.add_argument("--root", default=".")
    parser.add_argument("--registry", default=str(REGISTRY))
    args = parser.parse_args()

    root = Path(args.root).resolve()
    registry_path = root / args.registry
    if not registry_path.is_file():
        print(f"Missing UI route authority registry: {args.registry}")
        return 1

    registry = json.loads(registry_path.read_text(encoding="utf-8"))
    errors = validate(root, registry)
    if errors:
        for error in errors:
            print(f"ERROR: {error}")
        return 1

    print("Project-wide UI/route convergence registry validated.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
