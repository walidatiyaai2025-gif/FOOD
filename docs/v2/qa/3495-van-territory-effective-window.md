# V2 #3495 — Van assignment covers full Service Territory effective period

Parent MOB-V02 #2738 and P276 #2606; final V2 source acceptance #2503. Integration exclusively `Foodex_V2`; never main, release, tags, production, cPanel or APK distribution.

## Reproduced code-path defect

On original Foodex_V2, both VanRegistryService create/edit checked `ServiceTerritory` status and effective dates only at the assignment start **outside** their write transaction. A Van could be assigned through dates after the territory expires, including an open-ended assignment to a finite territory; a concurrent territory edit could invalidate the preflight. This is source-level evidence, not a report of actual live bad assignments.

## Fix / scope

- Keep canonical VanRegistryService and ServiceTerritory; no new territory ledger or duplicate route/menu. Validate after the canonical Van lock **inside** both existing assignment transactions. Lock the territory row to serialize the source lookup with concurrent status/date writes.
- Require active territory whose effective_from is null or <= assignment start and whose effective_until is null or >= assignment end. With half-open periods, exact end boundary is valid; open-ended assignment requires open-ended territory. Deny missing, future, inactive, expired or insufficient-window territory before assignment/audit mutation.
- Preserve optional null territory, active B2B Driver guard, active Van row guard, half-open primary interval overlaps, original audit, financial tenant/store isolation and existing Dashboard create/edit forms. Surface the same `territory_key` validation field with added locale-matched Arabic/English strings; no raw UI wording.
- Existing form placement: Field Operations -> Van Assignments, existing authorized create/edit flow (Reuse existing route/form); no new Sidebar item, modal, direct navigation or authorization grant. No responsive layout/RTL changes. No DB migration.

## Automated acceptance

New `backend/tests/Feature/V2VanTerritoryEffectiveWindowGuardTest.php` uses real RefreshDatabase and canonical VanRegistryService to cover bounded overtime denial and no audit/assignment effects, open-ended vs finite denial, edit rollback and unchanged history, exact-end permitted, truly unbounded territory permitted, future/inactive territory denial, optional null territory and AR/EN localized errors. Existing VanRegistryServiceTest and VanAssignmentManagementTest run in the complete Backend CI suite. Exact-final-head GitHub Policy/Isolation/Required CI must prove PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel, AR/EN and MySQL/Redis install/upgrade/recovery.

## Customer/Driver/Van impact

- Customer: no public API, Flutter, B2B quote/order or login change. Wholesale fulfillment stays B2B VAN ONLY.
- Driver: no public API, Flutter, B2C dispatch/collection or status change. Retail fulfillment stays B2C DRIVER ONLY.
- Van: no Flutter or public API payload change. The existing authorized Dashboard assignment route now correctly rejects periods beyond territory validity, while runtime remains server-authoritative.
- Backend/Dashboard: affected only in date validity enforcement and localized field-scoped errors; roles, permissions, tenant scope and front-end markup unchanged.

The patch proves **write-time** validity only; later managerial revocation of existing territories and historical reconciliation are separate operational decisions, not silently solved by this guard. Final manual device/staging/UAT, full visit/route/stock and B2B lifecycle acceptance remain open in #2738/#2606/#2503. No release production readiness asserted.
