# FOODEX 1.0.52 Release Notes

Status: final operational-completion distribution after umbrella #828 and integrated gate #841.

## Release identity

- Dashboard: `1.0.52`
- Customer app: `1.0.52+52`
- Driver app: `1.0.52+52`
- Customer runtime/footer identity: `1.0.52`
- Driver runtime/footer identity: `1.0.52`
- Driver diagnostics current identity: `1.0.52`
- Driver diagnostics build identity: `52`
- Published `1.0.51` remains immutable and is not reused.

## Included changes

- Publish the completed FOODEX operational-completion wave #828 after the final integrated #841 gate: gated Add Store and New Order popup wizards, central stable-code operational lookups, and the admin login default to the B2B dashboard.
- Ship the cumulative Catalog ZIP sample/preview/import flow for Products, Categories, Brands and images, plus server-authoritative OUT_OF_STOCK enforcement across API, Dashboard and Customer purchasing surfaces.
- Converge order lifecycle/status tabs and preserve Delivered history while removing completed work from active Driver queues; add complete Driver pickup/delivery details, Failed Delivery / تعذر التوصيل, and lifecycle-aware live Home card refresh.
- Publish Customer launch notification-campaign popups with authoritative eligibility/frequency/store-channel scoping, and Finance invoice From/To/customer filters with matching PDF and Excel exports.
- Publish current authoritative Customer and Driver App Review/Preview parity with visible freshness/stale/disconnected state and no fake live-data fallback.
- Preserve B2B/B2C/store isolation, AR/EN + RTL/LTR behavior and the green final integrated release gate; synchronize Dashboard, Customer and Driver release identities at 1.0.52 / mobile build 1.0.52+52 without changing production force-update or minimum-version policy.

## Dashboard update bundle

- Target version: `1.0.52`
- Minimum current version: `1.0.6`
- Contains migrations: generated manifest is authoritative.
- Requires full redeploy: `false`
- SHA-256: `214d3551ed09551c07530880232e622955771139566187ca1eb134ecd21391cf`.

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.
