# FOODEX 1.0.6 Dashboard Update Notes

Status: cumulative server/dashboard update from FOODEX 1.0.5 through the built-in System Update center.

## Update identity

- Target version: `1.0.6`
- Minimum supported current version: `1.0.5`
- 1.0.5 source baseline: `3dd8d81e4c2615aa2ec544130aa23f337e5ed21f`
- Package: `Release/Updates/FOODEX-Update.zip`
- SHA-256: `dcfc5834be9ff15a3bd3d66476bdc496b5390c10dd9773e9834431162fc57ad5`
- Runtime files in package: **94**
- Contains migrations: **Yes**
- Full redeploy required: **No**

## Included changes

- Multi-Store / Multi-Tenant architecture with authoritative B2B/B2C store/channel isolation.
- Store-owned catalogs, category/product ownership, split B2B/B2C customer domains, scoped business lookups and retail-store provisioning.
- Complete B2B/B2C dashboard order creation and management, pricing, totals, inventory reservation, lifecycle and driver assignment.
- Complete driver assigned-order management with active/completed/failed views, customer/address/items/payment detail, failure reasons and retry flow.
- Scheduled promotional notification campaigns with one-time/recurring schedules, pause/resume/cancel, tenant-scoped audiences and run history.
- Product galleries and dedicated category images with admin upload/change/remove controls and API/mobile image delivery.
- Production Android Firebase client configurations for the approved Customer and Driver application IDs.

## Installation through FOODEX

Open **Admin → System Update** and enter:

- Target version: `1.0.6`
- Minimum current version: `1.0.5`
- SHA-256: `dcfc5834be9ff15a3bd3d66476bdc496b5390c10dd9773e9834431162fc57ad5`
- Contains migrations: **checked**

Upload `FOODEX-Update.zip` and start the update. The updater performs package verification, file backup, database backup, maintenance mode, extraction, migrations, cache rebuild, health check and rollback on failure.

## Mobile distribution

This package updates the server/dashboard only. It does not overwrite mobile binaries already installed on devices. Customer/Driver mobile UI changes require separately distributed mobile builds. Android/iOS production signing and final physical-device acceptance remain external release evidence under #124.
