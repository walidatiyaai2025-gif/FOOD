# FOODEX 1.0.71 Release Notes

Status: Dashboard update release candidate from current main.

## Release identity

- Dashboard / repository release: `1.0.71`
- Customer source identity: `1.0.71+71`
- Driver source identity: `1.0.71+71`
- Van source identity: `1.0.71+71`
- Android publication / installed-APK policy: unchanged until mobile artifacts are explicitly published
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Include Issue #1261 / PR #1262: three exact legacy Customer 360 reports with authoritative B2B ledger/invoice-item data, locked Arabic report structure, preview/print and real PDF download.
- Include PR #1265: live Van map presence and GPS heartbeat repair, including authenticated Van location updates and authoritative assignment/store scoping.
- Synchronize repository/mobile source identities only to satisfy the release contract; this Dashboard Update ZIP does not replace APKs already installed on devices.

## Validation contract

- Required CI, Repository Policy and Release Validation must pass on the exact release head.
- The generated `FOODEX-Update.zip` must target `1.0.71`, support the cumulative updater minimum, and report no full redeploy requirement.
- The exact generated package SHA-256 must be registered before merge to `main`.
