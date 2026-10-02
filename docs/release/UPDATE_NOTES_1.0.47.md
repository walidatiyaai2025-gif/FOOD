# FOODEX 1.0.47 Release Notes

Status: synchronized post-1.0.46 Dashboard, Customer and Driver release.

## Release identity

- Dashboard: `1.0.47`
- Customer app: `1.0.47+47`
- Driver app: `1.0.47+47`
- Customer runtime/footer identity: `1.0.47`
- Driver runtime/footer identity: `1.0.47`
- Driver diagnostics current identity: `1.0.47`
- Driver diagnostics build identity: `47`
- Published `1.0.46` remains immutable and is not reused.

## Included changes

- Driver post-1.0.46 fixes tracked through #761/#763: secure Remember Me, optional fingerprint / Face ID sign-in, Today / All / From-To delivery filters, and one accepted-delivery Receive / Failed execution contract across B2B and B2C.
- Customer Commerce V4 (#764, #765-#773): one Customer login identity across Retail and Wholesale, Dashboard-managed Retail banners that rotate every 5 seconds and open the exact store, authoritative store-scoped cart/checkout/order routing, unified auth resume, complete Retail and Wholesale purchase journeys, and converged Dashboard order operations.
- Customer App Preview now mirrors the corrected shared Flutter runtime, keeps authentication identity separate from commerce context, preserves exact store/channel authorization, and keeps Draft vs Published configuration isolated.
- Existing tenant isolation, self-store purchase protection, Wholesale tier/MOQ rules, notification routing and Driver operational boundaries remain authoritative.

## Dashboard update bundle

- Target version: `1.0.47`
- Minimum current version: `1.0.6`
- Contains migrations: generated manifest is authoritative.
- Requires full redeploy: `false`
- SHA-256: generated package metadata and immutable release registry are authoritative.

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.
