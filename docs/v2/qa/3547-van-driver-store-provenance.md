# V2 #3547 — canonical B2B Driver store provenance for legacy Van bridge

Parents: #2738 / #2782 / #2503. One PR into `Foodex_V2` only; no main, tag, release, staging deploy, cPanel or production.

## Source and exact authorization scope
A B2B Driver role plus a legacy `drivers.driver_type=b2b` value was previously enough for Van fallback when a selected assignment pointed at this driver. The independent Van seller can resolve from a valid B2B warehouse even if the Driver's own persisted `store_id` belongs to Retail/B2C. This leaves a source-level cross-channel bridge, separately identified in review of completed #3497. No production exploitation is claimed.

Change only `VanRuntimeContextResolver::canUseRuntime()`'s **legacy B2B_DRIVER** fallback, joining `drivers.store_id → stores.store_type_id → store_types.code` and demanding the driver belongs to the caller, driver_type=b2b, driver active, driver store active and classified B2B. Reject orphan, Retail, inactive or foreign profiles; do not pick another profile when the context-specified driver is bad. Preserve explicit `van.login` path (subject to existing active-user check), `resolve()` seller filter, auth status/response, authorized B2B execution and representative-only Van assignment. No DB writes, migration, locale message, route, permission grant or new screen.

## Affected surface matrix
- Backend / Van auth: affected; existing session issuance and runtime middleware now fail closed for contradictory historical Driver store records, same HTTP 403/JSON contract
- Dashboard: not affected; assignment UI and write guard #3433 unchanged
- Customer: no public API or Flutter/DTO impact; B2B fulfillment remains VAN ONLY
- Driver: no Driver API/Flutter/dispatch change; Retail B2C fulfillment remains DRIVER ONLY
- Van: unauthorized legacy Van login/execution denied using existing 403 contract, no Flutter schema or navigation modification; broader Van mobile/UAT belongs to #2738

## Regression
`backend/tests/Feature/V2VanB2bDriverStoreProvenanceTest.php` seeds synthetic B2B and B2C stores, real driver and historical assignment rows, tests Retail-typed b2b mismatch with B2B seller warehouse, inactive B2B driver store, valid B2B fallback/login, foreign and wrong driver, inactive driver, no side-effect mutation. Check localized existing errors without new language strings. Full exact-head Policy, V2 Isolation, Required CI Laravel/Pint/PHPStan/security/AR-EN/MySQL/Redis must finish GREEN before any Foodex_V2 merge. Manual/UAT and all V2 end gates remain #2503.
