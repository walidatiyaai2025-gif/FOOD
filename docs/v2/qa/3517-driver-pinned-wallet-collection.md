# V2 #3517 — Driver wallet identity bound to authorized Retail runtime

Parent: #2736 MOB-D03, #2735, #2503. Target `Foodex_V2` ONLY.

## Source and security boundary

The existing `EnsureDriverRuntimeAccess` middleware checks Driver App token, B2C_DRIVER role, `deliveries.b2c.execute`, authoritative active Retail store, then pins `driver_runtime_context.driver_id`. Before #3517, `DriverCollectionController::driverContext()` reselected the user's **first** active Driver row independently and accepted either B2B or B2C. A retained historical B2B row could precede the authorized B2C profile, causing an incorrect context or denial. This is a verified static mismatch, not a claim of exposure of live records.

All wallet, remittance and assignment collection handlers share `driverContext()`. They now require the middleware-pinned exact active B2C driver belonging to the authenticated user, recheck Retail store/channel against the pinned context and preserve the canonical `DriverTenantScope::resolve` validation (including legacy unscoped 409). No Driver B2B fallback or direct legacy finance access. Existing account/assignment filters and custody idempotency/maker-checker service remain authoritative.

## HTTP acceptance matrix

- Authorized mixed-profile B2C_DRIVER token, historical Wholesale row created before active Retail row: GET wallet returns only Retail custody account.
- Same token attempting POST remit against historical B2B account: 404, no new remittance.
- Retail custody from actual collection: POST remit succeeds and same-key retry returns one pending remittance.
- Historical B2B-only profile (even if B2C_DRIVER role remains): GET wallet and POST remit 403.
- Wrong app-scoped token: GET wallet 403.
- Existing assignment collection endpoint shares the same newly pinned context; the separate `driver.location.fresh` guard is preserved. No new route/payload.
- AR/EN: no new UI copy or response fields; existing status/JSON shape preserved. Existing messages are unchanged except scoped 403 when middleware identity context is absent.
- Security: no cross-store/channel/customer/driver access, no database migrations or changes to custody ledger arithmetic.

## App impact

| Surface | Impact |
| --- | --- |
| Laravel Backend API | Affected: Driver wallet/collection/remittance identity enforcement |
| Dashboard | None: no route or view altered |
| Customer Flutter | None: no public API/contract change |
| Driver Flutter | Compatible: existing wallet/collection/remit JSON remains unchanged |
| Van Flutter | None: separate B2B Van operations and custody unchanged |

## CI checklist

Focused test: `php artisan test --filter=V2DriverPinnedCollectionIdentityTest`. Required on exact final HEAD: Laravel full tests; Pint/PHPStan/Composer security/OpenAPI; AR/EN; MySQL/Redis deployment acceptance; Repository Policy; FOODEX V2 Isolation; terminal Required CI. Record actual final run URLs/SHAs after verification. Source-code completion may defer manual staging UAT only to #2503, not unfinished tests.

No `main`, release, tag, APK publish, cPanel or production changes.
