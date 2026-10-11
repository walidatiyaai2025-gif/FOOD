# FOODEX 1.0.71 Release Notes

Status: Dashboard-only production release candidate from current main.

## Release identity

- Dashboard / repository release: `1.0.71`
- Customer Android release policy: unchanged
- Driver Android release policy: unchanged
- Van Android release policy: unchanged
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Include Issue #1261 / PR #1262: three exact legacy Customer 360 reports with authoritative B2B ledger/invoice-item data, locked Arabic report structure, preview/print and real PDF download.
- Include PR #1265: live Van map presence and GPS heartbeat repair, including authenticated Van location updates and authoritative assignment/store scoping.
- Preserve existing Customer/Driver/Van APK release policies; this package updates the Dashboard/backend only and does not replace installed mobile APKs.

## Validation contract

- Required CI and Repository Policy must pass on the exact release head.
- The generated `FOODEX-Update.zip` must target `1.0.71`, support the cumulative updater minimum, and report no full redeploy requirement.
- The exact generated package SHA-256 must be registered before merge to `main`.
