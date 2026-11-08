#!/usr/bin/env python3
"""Fail release publication when a 1.0.126+ Update ZIP lacks #2482 runtime capabilities."""

from __future__ import annotations

import argparse
import re
from pathlib import Path
from zipfile import BadZipFile, ZipFile


class CapabilityGateError(RuntimeError):
    """An update package does not prove its required release capabilities."""


# These files are application-owned runtime files, not production secrets or user data.
# Update this contract deliberately if a later release replaces a canonical capability.
REQUIRED_FILES: dict[str, tuple[str, ...]] = {
    "backend/app/Http/Controllers/Admin/CustomerGroupManagementController.php": (
        "commercial_customer_group_members",
    ),
    "backend/resources/views/admin/customer-groups.blade.php": (
        "data-customer-group-bulk",
    ),
    "backend/app/Http/Controllers/Admin/OrderOperationsController.php": (
        "applyCustomerServiceDiscount",
    ),
    "backend/resources/views/admin/order-operations.blade.php": (
        "assigned_user_ids[]",
        "customer_service_discount.label",
    ),
    "backend/app/Http/Controllers/Admin/CommercialDashboardController.php": (
        "audience_customer_group_ids",
    ),
    "backend/resources/views/admin/commercial-dashboard.blade.php": (
        "audience_customer_group_ids[]",
    ),
    "backend/app/Services/FlashOfferAudienceService.php": (
        "audience_customer_group_ids",
    ),
    "backend/app/Http/Controllers/Api/V1/StorefrontController.php": (
        "published",
    ),
    "backend/app/Http/Controllers/PublicWholesaleStorefrontController.php": (
        "section",
    ),
    "backend/app/Services/PlatformOwnerProvisioner.php": (
        "platform_owner_registry",
    ),
    "backend/app/Support/AdminNavigation.php": (
        "customer-groups.index",
        "isPlatformOwner()",
    ),
    "backend/resources/views/admin/enterprise-modules.blade.php": (),
    "backend/app/Console/Commands/ProvisionPlatformOwner.php": (),
    "backend/database/migrations/2026_11_08_070000_create_order_customer_service_discounts.php": (),
    "backend/database/migrations/2026_11_08_071000_create_platform_owner_registry.php": (),
    "backend/lang/ar/customer_groups.php": (),
    "backend/lang/en/customer_groups.php": (),
    "backend/lang/ar/customer_service_discount.php": (),
    "backend/lang/en/customer_service_discount.php": (),
    "backend/routes/web.php": (
        "/b2b/customers/groups",
        "/b2c/customers/groups",
        "/operations/orders/{order}/customer-service-discount",
        "/administration/modules",
    ),
}


def verify_update_archive(path: Path, target_version: str) -> None:
    """Verify the generated ZIP's actual content, not only source-tree declarations."""
    if re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", target_version) is None:
        raise CapabilityGateError("Target version must be canonical MAJOR.MINOR.PATCH.")

    try:
        with ZipFile(path) as archive:
            names = archive.namelist()
            if len(names) != len(set(names)):
                raise CapabilityGateError("Update ZIP has duplicate filenames.")

            if "VERSION" not in names:
                raise CapabilityGateError("Update ZIP is missing VERSION.")
            actual = archive.read("VERSION").decode("utf-8").strip()
            if actual != target_version:
                raise CapabilityGateError(
                    f"Update ZIP VERSION {actual!r} does not match target {target_version!r}."
                )

            for required, markers in REQUIRED_FILES.items():
                if required not in names:
                    raise CapabilityGateError(f"Update ZIP is missing required capability file: {required}")
                content = archive.read(required).decode("utf-8")
                if not content.strip():
                    raise CapabilityGateError(f"Capability file is empty: {required}")
                for marker in markers:
                    if marker not in content:
                        raise CapabilityGateError(
                            f"Capability marker {marker!r} missing from {required}"
                        )
    except (BadZipFile, OSError, UnicodeDecodeError, KeyError) as exc:
        raise CapabilityGateError(f"Invalid or unreadable release Update ZIP: {exc}") from exc


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--target-version", required=True)
    args = parser.parse_args()
    verify_update_archive(args.archive, args.target_version)
    print(f"FOODEX release capability ZIP gate PASS: {args.target_version}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
