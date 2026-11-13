# V2 #3347 — Driver login and token store-readiness boundary (MOB-D02)

**Document authority:** P110/T115R04, `docs/v2/V2_P136_FULFILLMENT_ACTORS.md`. Parent #2735, master #2503. **Only Foodex_V2; no main, tag, release or production.**

## Root cause: stale/invalid Retail store session

The pre-existing `AuthController::login` and `AuthController::mobileTrialLogin` required an active `drivers` row of type B2C and an actor with `B2C_DRIVER` / `deliveries.b2c.execute`. Neither checked that `drivers.store_id` belongs to an **active** Retail/B2C store before issuing `app:driver` tokens. `EnsureDriverRuntimeAccess` repeated this omission for already-issued tokens. Downstream `DriverTenantScope::resolve` checked the store in several operations, but that late check did not prevent issuing an invalid execution session or guarantee consistent runtime-boundary denial.

The safe fix is `DriverTenantScope::eligibleRetailDriver(User)`: it left-joins the real `drivers`, `stores` and `store_types` rows to require active driver, B2C driver type, existing active store and B2C store type **in SQL**, before checking existing user role/permission. The middleware alone opts into a null-store compatibility branch so an existing historic token reaches the established downstream **409** missing-store recovery response, while new logins never get this exception. It returns the canonical Driver row (not a synthetic/forged store identifier). Neither user roles nor store assignment is changed.

`drivers.user_id` has an authoritative UNIQUE constraint in migration `2026_09_24_000500_create_delivery_platform_foundation.php`: a hypothetical two-driver-row collision was investigated and rejected as impossible in the current schema. This issue therefore **does not** change Driver row selection or implement unrelated dual-profile behavior.

## Real HTTP matrix / security

| Route | Healthy Retail Driver | Retail store suspended | Driver points to Wholesale-typed store |
| --- | --- | --- | --- |
| `POST /api/v1/auth/login` with `app=driver` | 200 and `app:driver` bearer | 403, **no new token** | 403, **no new token** |
| `POST /api/v1/auth/mobile-trial` with `app=driver` | 200 (when trial enabled) | **422** validation error, **no new token** | **422** validation error, **no new token** |
| `GET /api/v1/driver/wallet` with previously issued `app:driver` token | 200 (if role/driver remain valid) | 403 at middleware | 403 at middleware |
| `GET /api/v1/driver/assignments` and `POST /driver/location/heartbeat` | Existing downstream authorization unchanged | 403 before access/write | 403 before access/write |

Additional guards unchanged: `auth:sanctum`, `active.user`, `tokenCan('app:driver')`, `hasRole('B2C_DRIVER')`, `hasPermission('deliveries.b2c.execute')`, the Driver assignment scope checks and the Driver Tenant authority. For a historical driver with `store_id=NULL`, the existing token still receives a downstream **409** rather than a new 403, and the API does **not** mint fresh driver tokens for that account; this preserves the established `DriverTenantScope::resolve` recovery contract. Disabled **user** accounts remain rejected by `active.user`. The new helper is read-only, and no schema, audit, queue or ledger mutation is introduced.

## Per-surface classification

- **Backend/API — affected:** eligibility-only 403 security correction on Driver login, trial and runtime middleware; success response/schema/endpoint unchanged. No OpenAPI route or response-shape modification; pre-existing 403 guard is strengthened.
- **Driver App — affected by server authorization:** linked parent #2735; existing denied-session state applies. No Flutter files or client payloads changed; an explicit localized Driver UI E2E/AR-EN confirmation remains under #2735 and is not claimed by this atom.
- **Customer App — not affected:** `app:customer` registration/login, customer routes and store contexts untouched. Distinct app audience and auth behavior unchanged.
- **Van App — not affected:** `app:van` login, assignment/permission resolution and B2B routes unchanged. B2B=VAN ONLY remains the policy.
- **Dashboard — not affected:** no Blade, admin routes, navigation, permissions or UI changes. Existing store administration remains the source of `stores.is_active`.

## Reproduction and acceptance

New `backend/tests/Feature/V2DriverRetailStoreSessionBoundaryTest.php` uses seeded real B2C/B2B store types, Retail Driver role, persisted Driver row and Sanctum PersonalAccessToken. HTTP tests verify valid Driver login and wallet, loss of access for existing tokens immediately after store is disabled, denial of new login/trial tokens, forged historical Retail driver→Wholesale store type denied, no new location/remittance writes, and restoration when store is reactivated. Existing `DriverAssignmentLifecycleTest` explicitly requires the historic unscoped-driver **409** and is retained unchanged. Existing DriverB2BCutoverTest and AuthFoundationTest ensure legacy B2B and dual-app account boundaries survive.

Expected GitHub gates: Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate including backend PHP/Pint/PHPStan/security, full Laravel HTTP tests, AR/EN quality and MySQL/Redis install/upgrade/recovery. All must be terminal **SUCCESS on final PR head**; otherwise repair this same branch. No manual production account/store edits or deployment are authorized.

**Close only atomic #3347** after confirmed merge+CI. Parent #2735 remains OPEN for broader Driver wallet/assignment/notification/device E2E, accessibility/AR-EN and release readiness. This is not a signed release or production fix until independently deployed by owner order.
