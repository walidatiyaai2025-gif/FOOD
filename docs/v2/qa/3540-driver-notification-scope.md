# V2 #3540 — Effective Driver notification audience

## Source defect and correction

`NotificationAudience::context()` previously assembled the Driver persona from active B2C drivers but merged **every** historical `drivers.store_id` into the audience, including inactive, Wholesale and foreign-store rows. The live schema enforces one Driver row per user, so the demonstrable risk is a dual-persona Customer in Store A whose lone inactive Driver profile points to Store B; the stale Driver store incorrectly widened that Customer's B2C notification audience. This can widen store-specific notification visibility for a user with multiple historical profiles; this is not an assertion of production exploitation.

The canonical `DriverTenantScope::eligibleRetailDriver($user)` now supplies both the Driver persona/channel and its only Driver-derived store ID. Its established requirements are an active B2C driver linked to an active Retail B2C store. Other independent Customer and Dashboard role/store sources are preserved. Authorized B2B Van store scope is retained from the selected `VanRuntimeContextResolver` assignment rather than from untrusted legacy Driver rows. Existing `NotificationAudience::apply()` remains authoritative for index/show/read and `matchesTarget()` for target/sending eligibility; no new server-side policy branch is introduced. Existing B2B VAN ONLY / B2C DRIVER ONLY invariant is unchanged.

## Exact implementation and tests

- Changed `backend/app/Services/NotificationAudience.php`, reusing `DriverTenantScope`. No migration, notification schema, user role grant, public route, new UI or translation.
- Added `backend/tests/Feature/V2DriverNotificationEffectiveStoreScopeTest.php` with genuine Laravel/Sanctum HTTP and service regressions:
  - Active Retail Customer Store A plus inactive B2C Driver Store B (one Driver row per user): Customer notification at A visible, foreign B denied in list/detail/read, no unauthorized read record, own read succeeds.
  - Valid active B2C Driver Store A sees its notifications only; a driver with inactive Retail store or B2C type linked to the Wholesale store gets no Driver audience.
  - Valid B2B Driver-to-Van compatibility assignment retains its selected active B2B warehouse/store notification target, while B2B Driver does not regain Driver-app execution.
- No real device/account identifier, FCM credential, release package, production action or external notification delivery is involved.

## MOBILE IMPACT — Customer/Driver/Van

## Cross-app and dashboard matrix

| Surface | Classification |
| --- | --- |
| Driver | Driver eligibility and read/mark/push targeting now require an active Retail driver profile; no Flutter request or JSON schema changes |
| Customer | Dual-persona Customer is protected from obsolete Driver store widening; Customer's own authoritative store query and Flutter/API contract unchanged |
| Van | Canonical selected active B2B Van assignment contributes scoped warehouse store ID instead of raw legacy Driver association; no auth elevation, B2B Van notifications remain visible |
| Dashboard | Existing authorized roles/store IDs and notification navigation unchanged |
| AR/EN, RTL/LTR, accessibility | No new visible strings, layouts or resources; tests read English payload from the same existing localization fields |

## Acceptance and isolation

Issue #3540 parent #2735/#2740/#2503. Issue-scoped branch `fix/3540-v2-driver-notification-effective-store`, PR base **Foodex_V2 ONLY**. All exact-final-HEAD Policy, V2 Isolation, FOODEX Required CI (Laravel/security/Pint/PHPStan/AR-EN/MySQL-Redis, required-ci-gate) must be terminal SUCCESS and branch/lease review rechecked before merge. Human staging/device UAT is tracked by the existing parents and not asserted complete. No `main`, tag, release, cPanel, deployment or production action.
