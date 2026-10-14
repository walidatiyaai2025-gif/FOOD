# FOODEX 1.0.78 — Dashboard Update Release Notes

## Release identity

- Dashboard / repository release: `1.0.78`.
- Customer source identity: `1.0.78+78`.
- Driver source identity: `1.0.78+78`.
- Van source identity: `1.0.78+78`.
- The mobile identity synchronization satisfies the repository release-line contract; this 1.0.78 change does not introduce new Customer, Driver, or Van product behavior.
- The Dashboard Update ZIP remains independently deployable and does not contain `apps/**` mobile source files.

## Dashboard scope

- Make `/admin/operations/orders` the canonical order-management surface.
- Preserve strict server-side B2B/B2C channel and store isolation: B2B operators cannot read or mutate B2C orders, and Retail operators cannot read or mutate B2B orders.
- Redirect the legacy B2B/B2C Orders pages to the canonical Operations view while retaining backward-compatible mutation endpoints.
- Keep order actions under the contextual three-dot menu and only expose actions permitted by the order state and the actor's manage, dispatch, and finance scopes.
- Preserve B2B Van fulfillment and B2C Driver fulfillment.
- Add safe pending-order editing through `AdminOrderManagementService`, including authoritative re-pricing and stock validation.
- Add regression coverage for cross-channel denial, canonical redirects, multi-channel switching, and contextual action rendering.

## Deployment and rollback

- Dashboard-only server update package.
- No database migrations are introduced by #1426.
- Rollback anchor: branch `rollback/1.0.77-pre-unified-orders`.
- Rollback baseline SHA: `194c9f0ccfba6be8e45ce7d1f62d6b1e265c5a2f`.
- If 1.0.78 is rolled back before any later schema-changing release, restore the 1.0.77 Dashboard code package/baseline; no database downgrade is required for this change.

## Publication contract

The generated `FOODEX-Update.zip`, manifest, file list, and SHA-256 checksum must come from the canonical update workflow on the exact validated 1.0.78 source lineage. Generated artifacts must not be hand-edited or relabeled.
