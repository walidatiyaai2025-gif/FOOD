# FOODEX 1.0.6 Release Notes

Status: cumulative dashboard/server update from FOODEX 1.0.5. The System Update package is designed for the existing FOODEX Update Center and contains database migrations.

## Update identity

- Target version: `1.0.6`
- Minimum supported current version: `1.0.5`
- 1.0.5 source baseline: `3dd8d81e4c2615aa2ec544130aa23f337e5ed21f`
- Update package: `FOODEX-Update.zip`
- Contains migrations: **Yes**
- Full redeploy required: **No**
- Production web/API origin remains `https://foodex.50sols.com`.

## Included changes

### Multi-tenant platform
- Store/Tenant context is authoritative across catalog, customers, inventory, orders, drivers, marketing, reporting and audit data.
- B2B wholesale and B2C retail customer domains are separated and enforced server-side.
- Catalogs, categories and products are store-owned.
- Lookup Management and Super Admin retail-store provisioning are included.
- B2B and B2C workspaces are isolated with permission + ownership checks.

### Order management
- B2B and B2C administrators can create orders from the dashboard.
- Pending orders can be edited with server-calculated totals, discounts, delivery fees and payment-method validation.
- B2B pricing tiers/minimum quantities and B2C store pricing are enforced.
- Inventory reservation/release/consumption is synchronized with order lifecycle.
- Driver assignment is available from order management.

### Driver operations
- Drivers see only assignments for their own driver identity, channel and store.
- Active/completed/failed filtering and full order detail are included.
- Delivery lifecycle supports accepted, picked up, out for delivery, delivered, failed and retry-after-failure.
- Failure notes, audit records and dashboard operational notifications are persisted.

### Promotional notifications
- B2B/B2C promotional campaigns support send-now, one-time scheduling and recurring schedules.
- Campaigns can be paused, resumed or cancelled.
- Audience scoping prevents cross-store and cross-channel delivery.
- Campaign run history records delivery counts and idempotent scheduler execution.

### Catalog images
- Categories have dedicated uploaded images.
- Products support multi-image galleries, primary image selection, ordering and removal.
- B2C and B2B APIs expose stable image URLs.
- Customer mobile surfaces render category/product images and product galleries.
- Legacy categories without a dedicated image preserve the existing product-image fallback.

### Firebase Android
- Production Android Firebase client configs are present for:
  - Customer: `com.fiftysolution.foodex.customer`
  - Driver: `com.fiftysolution.foodex.driver`

## Update procedure

1. Back up the production/staging environment.
2. Open **Admin → System Update**.
3. Upload `FOODEX-Update.zip`.
4. Enter:
   - Target version: `1.0.6`
   - Minimum current version: `1.0.5`
   - SHA-256: use the value from `FOODEX-Update.json`
   - Contains migrations: checked
5. Start the update. FOODEX performs file backup, database backup, maintenance mode, extraction, migrations, cache rebuild and health check. A failure triggers rollback.

## Mobile note

The dashboard/server update does not replace already-installed mobile binaries. Customer/Driver UI changes in this release require updated mobile APK/IPA distribution separately. Android Firebase configuration is included in source; production signing and iOS APNs/signing remain external release inputs.

## External production evidence

Issue #124 remains the release-only gate for real production/staging backup identifiers, signing inputs, secrets, deployment health evidence and physical-device acceptance. Repository CI evidence does not fabricate those external inputs.
