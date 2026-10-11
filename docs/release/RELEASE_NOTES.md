# FOODEX 1.0.72 Release Notes

Status: release candidate for the post-1.0.71 Dashboard update.

## Release identity

- Dashboard / repository release: `1.0.72`
- Customer source identity: `1.0.72+72`
- Driver source identity: `1.0.72+72`
- Van source identity: `1.0.72+72`
- Android publication / installed-APK policy: unchanged until mobile artifacts are explicitly published
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Promote all authoritative Dashboard/backend changes merged after FOODEX 1.0.71 through PR #1287 into the FOODEX 1.0.72 Dashboard update.
- Ship Wholesale/Retail commerce fixes from PRs #1268 and #1269, including optional Wholesale pricing, principal-stock behavior, Customer commerce UX hardening, and normalized EGP/unit display formatting.
- Improve Dashboard operations from PRs #1274, #1275, #1279 and #1285: customer-first order notifications, simplified B2B product/inventory navigation, short customer-facing order/invoice display numbers while preserving immutable internal references, and Sales Control moved to the authoritative B2B context.
- Include Van production fixes from PRs #1281 and #1283: remembered-session biometric unlock and authoritative Wholesale seller-store catalog resolution.
- Include engineering/release hardening from PRs #1271 and #1287, including safer Flutter authoring gates and Fast Release Pipeline v2.
- Synchronize Customer, Driver and Van source identities at 1.0.72+72 for the release contract; the Dashboard Update ZIP remains independently deployable and does not replace installed APKs unless mobile artifacts are explicitly published.

## Validation contract

- Required CI, Repository Policy, APP-PREVIEW acceptance, Store Readiness and Update Bundle validation must pass on the exact release head.
- The generated `FOODEX-Update.zip` must target `1.0.72`, support the cumulative updater minimum, and report no full redeploy requirement.
- The exact generated package SHA-256 must be registered in the append-only release registry before merge to `main`.
