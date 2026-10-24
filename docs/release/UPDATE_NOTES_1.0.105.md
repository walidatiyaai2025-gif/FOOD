# FOODEX 1.0.105 Dashboard Update Notes

FOODEX 1.0.105 is a dashboard/backend-only update built from the authoritative `main` lineage through `5efd2aaa9a72e72ee564d1a9dbd7d5328e28e895`.

## Included

- Independent Wholesale Customer App and Public Web storefront administration.
- Dedicated Public Wholesale Web draft/preview/publish lifecycle on the `b2b_web` channel.
- Separate web settings, sections and banners so web content changes do not mutate the Customer App storefront.
- Public Wholesale Web enable/disable state, branding, SEO metadata, brands/categories/products presentation and management-login access.
- Price-free and purchase-free public web catalog behavior remains enforced; purchasing continues through the mobile application.
- Cumulative Dashboard Update package with updater recovery/runtime safeguards, generated manifest, file list and SHA-256 checksum.

## Build scope

- Dashboard / backend update: `1.0.105`.
- Customer App: unchanged at `1.0.104+104`.
- Driver App: unchanged at `1.0.104+104`.
- Van App: unchanged at `1.0.104+104`.
- No Android APK rebuild is requested for this promotion.
- Customer/Driver web preview runtimes may be regenerated only because they are embedded Dashboard preview assets, not mobile application releases.

## Deployment

- Production API remains `https://foodex.50sols.com`.
- Use the generated `FOODEX-Update.zip` and matching JSON / checksum / file-list artifacts from the canonical Update Bundle workflow.
- Source must remain the exact 1.0.105 release-branch commit; do not reuse 1.0.104 package metadata.
