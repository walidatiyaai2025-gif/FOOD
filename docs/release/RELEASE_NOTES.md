# FOODEX 1.0.124 — Synchronized Release Notes

## Identity

- Dashboard / repository: `1.0.124`.
- Customer App: `1.0.124+124`.
- Driver App: `1.0.124+124`.
- Van App: `1.0.124+124`.
- Prior official tags and downloadable assets remain immutable.

## Changes

- Enable Customer App — Wholesale Offers page visibility on B2B Flash Offers without requiring per-customer, customer-group, route or region targeting. Empty audience dimensions are unrestricted; the visibility switch remains independent from targeting.
- Migrate legacy wholesale Van-only promotions to include Customer App discovery while retaining existing audience restrictions, offer products, expiry, quota and inventory conditions.
- Preserve wholesale VAN ONLY ordering/fulfillment. Customer display selection never authorizes direct B2B Customer checkout or B2C Customer push notifications. Keep B2C DRIVER ONLY fulfillment and tenant isolation.
- Build a synchronized release from the protected-main code containing PR #2477 / #2476 and completed v1.0.122→v1.0.123 registry recovery; do not alter historic releases.

## Production acceptance

The canonical Release Factory must build from the exact final protected-main source, pass Required CI and security/localization gates, clean-install the exact Laravel Setup ZIP, generate and verify the actual Dashboard Update ZIP/Manifest/SHA-256, confirm upgrade/recovery compatibility, and publish matching immutable GitHub Release assets. Protected-main registry synchronization must pass before marking 1.0.124 production-ready. Dashboard 1.0.123 is the immediate supported upgrade source; older installations must use the explicit intermediate paths in the published manifests. Never bypass installer/updater verification or skip a backup.
