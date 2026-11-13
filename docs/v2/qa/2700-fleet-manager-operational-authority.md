# V2 #2700 — Fleet Manager / FLEET_MANAGER (T122R20)

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, T122R20; parent #2503. Integration target **Foodex_V2 ONLY**. No production, main or release activity.

## Root cause and bounded runtime correction

The existing, seeded global FLEET_MANAGER role already grants exactly `field_ops.manage`, `territories.manage`, `drivers.tracking.view/history/manage`, `drivers.b2b.view/manage`, and `reports.view`; no ERP module is required. However `config('admin.channels.b2b.global_roles')` omitted this role, so `OperationalTenantScope::allowedStoreIds` could not resolve authorized B2B stores and the actual Wholesale Dashboard/Field Operations route was unreachable. Existing `FieldOperationsController::b2bStoreIds` also restricted nonlisted roles to `user_store_roles`, which a tenant-wide Fleet Manager does not have.

Reused the **existing** B2B channel allowlist and the single canonical `OperationalTenantScope` decision: Fleet Managers now get B2B only, and their Field Operations overview's B2B store filter uses `allowedStoreIds($user, 'drivers.b2b.view', 'B2B')`. This honors inactive identities, effective permission, active store, channel and per-store/global DENY overrides. Retail B2C channel admissions, other roles, modules, routes and independent HTTP permission checks are unchanged. Dispatch Supervisor #2701 and Treasury Officer #2699/#2851 still own their own role/SoD decisions; this patch does **not** add them.

## Verification / impact matrix

`backend/tests/Feature/V2FleetManagerOperationalAuthorityTest.php` exercises canonical seeded role grants and AR/EN presentation; multi-locale real HTTP for authorized B2B Fleet Vans/overview and one canonical Sidebar; direct Retail dashboard, foreign Retail store, finance and security 403; a second B2B store with explicit user STORE DENY rejected from the actual overview while the remaining B2B store works; global DENY and inactive/roleless refusal. Existing Field Operations tenant isolation tests (#2589/#2596/#2599/#2600) remain authoritative for broader route actions.

- **Backend / Dashboard:** narrow runtime allowlist and Field Operations read-scope correction, new Laravel HTTP regression; no Finance/SoD, storage or database migration change.
- **Customer:** no API/schema/auth or Flutter change.
- **Driver:** no app changes or B2C dispatch grant. B2C remains **DRIVER ONLY**.
- **Van:** existing B2B fleet management UI becomes reachable to authorized Dashboard Fleet Manager. No Van API, Van app code, assignment/visit payload or dispatch lifecycle change; B2B remains **VAN ONLY**.

## Mandatory gates

- Reused original issue branch `test/2700-v2-fleet-manager-operational-authority` and its recorded lease; no replacement branch or parallel PR.
- Before merge: exact-head Repository Policy, FOODEX V2 Isolation, full FOODEX Required CI (Laravel, Pint/PHPStan/security, AR/EN, MySQL/Redis fresh install/upgrade/recovery, terminal required-ci-gate) all must be **SUCCESS**. CI has not been asserted as passing at creation time.
- Manual browser/real-device RTL/LTR, accessible keyboard/responsive UAT: **DEFERRED_TO_V2_FINAL** in owner #2503/#2750. No claim of live data or production acceptance.
- Merge solely into `Foodex_V2`; no `main`, tags, release, cPanel or production. If automated tests reveal an additional runtime defect, fix this same PR with a new checked exact head; leave issue open if technical source acceptance remains incomplete.
