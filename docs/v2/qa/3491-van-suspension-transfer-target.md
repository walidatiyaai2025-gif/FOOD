# V2 #3491 — Active distinct transfer target for loaded Van suspension

Parent #2738 Van lifecycle, #2606 P276 and #2503 master. Integration: **Foodex_V2 only**. No production, main integration, release tags or mobile publishing.

## Source defect and security boundary

Canonical `VanRegistryService::suspend()` previously required only a non-null target for loaded work, but did not ensure the target was a different, active Van. Dashboard prechecks exclude self-target but provide no status check; a stale or direct service caller could write `transferred_to_van_id` pointing at the very Van being suspended, or at another suspended Van. This is a source-level risk, not proof that a live transfer occurred. Existing status and temporal safeguards #3433/#3487/#3489 are already integrated and must be preserved.

## Implementation

- Lock the source and provided target Van rows **in ascending numeric ID order** inside the existing database transaction, before source assignment rows. This avoids Van A→B / B→A inverse-row-lock patterns and serializes target status with concurrent suspension.
- When loaded work exists, reject absent, self, or non-active transfer target before any assignment status/dates/transferred ID or source status mutation. Leave unloaded suspension without target valid.
- Resolve new `van_assignment_guard.active_transfer_target_required` in matching Arabic and English locale files; existing `transfer_target` validation key and HTTP caller remain unchanged. No new database schema, endpoint, role, entitlement, UI, or mobile payload.
- This guards transfer-reference correctness at decision time. Actual physical stock movement and final custody/reconciliation are still separate, unsatisfied #2607/#2739/#2503 workflows; do not confuse this with full successful transfer.

## Tests and evidence

New `backend/tests/Feature/V2VanSuspensionTransferTargetGuardTest.php` covers self-target and suspended-target rejection without source/assignment mutation; successful distinct active target with target ID lower than source; unloaded suspension; AR/EN localized no-target denial. Existing VanRegistryServiceTest and temporal suspension tests remain part of full Laravel CI. Required final-head GitHub Actions: Repository Policy, FOODEX V2 Isolation, Required CI including PHP lint/Pint/PHPStan/security/OpenAPI, Laravel full test suite, AR/EN and MySQL/Redis fresh-install/upgrade/recovery.

Customer/Driver/Van impact: Customer has no public API or Flutter change; Driver has no API/Flutter or B2C dispatch change; Van has no Flutter/API contract change, but B2B transfer-reference validation is stricter in the existing authorized Dashboard flow. No new app code or mobile issue is necessary for a contract-preserving service guard.

## Per-surface classification

| Surface | Impact |
| --- | --- |
| Backend | Affected: Van suspension transfer-target authorization and lock ordering |
| Dashboard | Existing authorized suspension form receives localized `transfer_target` errors, no controller/menu changes |
| API | No public payload/route/permission change |
| Customer | No API/Flutter change, B2B stays Van-only |
| Driver | No API/Flutter change, B2C Driver-only |
| Van | No Flutter/API shape change; safer B2B transfer reference |

Atomic leaf may close only after exact-final-head required CI terminal green and reviewed issue-scoped PR merged to Foodex_V2. Broader Van mobility/stock/route and final UAT remain OPEN in #2738/#2606/#2739/#2503. No live/production claims.
