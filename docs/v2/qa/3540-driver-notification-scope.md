# V2 #3540 — Effective Driver notification audience

## Source defect and correction

`NotificationAudience::context()` previously assembled the Driver persona from active B2C drivers but merged **every** historical `drivers.store_id` into the audience, including inactive, Wholesale and foreign store rows. This can widen store-specific notification visibility for a user with multiple historical profiles; this is not an assertion of production exploitation.

The canonical `DriverTenantScope::eligibleRetailDriver($user)` now supplies both the Driver persona/channel and its only Driver-derived store ID. Its established requirements are an active B2C driver linked to an active Retail B2C store. Other independent Customer, Van and Dashboard role/store sources are preserved. Existing `NotificationAudience::apply()` remains authoritative for index/show/read and `matchesTarget()` for target/sending eligibility; no new server-side policy branch is introduced. Existing B2B VAN ONLY / B2C DRIVER ONLY invariant is unchanged.

## Exact implementation and tests

- Changed `backend/app/Services/NotificationAudience.php`, reusing `DriverTenantScope`. No migration, notification schema, user role grant, public route, new UI or translation.
- Added `backend/tests/Feature/V2DriverNotificationEffectiveStoreScopeTest.php` with genuine Laravel/Sanctum HTTP and service regressions:
  - Valid active Retail Driver at Store A plus inactive Retail Driver Store B and active legacy B2B Driver: Store A only, cross-store Store B list/detail/read hidden, no unauthorized read record, own read succeeds.
  - Active driver at inactive Retail Store and inactive profile at Store B + legacy B2B Driver: no valid Driver audience or notification visibility.
- No real device/account identifier, FCM credential, release package, production action or external notification delivery is involved.

## Cross-app and dashboard matrix

| Surface | Classification |
| --- | --- |
| Driver | Affected existing read/mark notifications and server-side push targeting; intentionally stricter active Retail profile/store eligibility, unchanged JSON schema and Flutter calls |
| Customer | No independent Customer identity/store query modified, no Flutter/API contract change |
| Van | No Van runtime eligibility query modified, B2B Van targeting retained |
| Dashboard | Existing authorized roles/store IDs and notification navigation unchanged |
| AR/EN, RTL/LTR, accessibility | No new visible strings, layouts or resources; tests read English payload from the same existing localization fields |

## Acceptance and isolation

Issue #3540 parent #2735/#2740/#2503. Issue-scoped branch `fix/3540-v2-driver-notification-effective-store`, PR base **Foodex_V2 ONLY**. All exact-final-HEAD Policy, V2 Isolation, FOODEX Required CI (Laravel/security/Pint/PHPStan/AR-EN/MySQL-Redis, required-ci-gate) must be terminal SUCCESS and branch/lease review rechecked before merge. Human staging/device UAT is tracked by the existing parents and not asserted complete. No `main`, tag, release, cPanel, deployment or production action.
