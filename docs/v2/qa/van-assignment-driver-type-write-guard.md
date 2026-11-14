# V2 #3433 — fail-closed B2B Van assignment Driver write authority

Parent #2738 (MOB-V02), source P110/T115R04, #2911 B2B/B2C runtime cross-channel guard, master #2503. **Base and integration: Foodex_V2 ONLY.** No main, release, tags, cPanel or production.

## Root cause and remediation

The existing Dashboard Field Operations assignment forms validated `driver_id` only by `exists:drivers,id`. The shared `VanRegistryService` was authoritative for assignment creation and editing but persisted that ID without checking `drivers.driver_type`, active status or attached User status. A Retail Driver or disabled Driver could be entered into an active B2B Van assignment even though `VanRuntimeContextResolver` might later deny app execution.

Both `assign()` and active `updateAssignment()` now validate an explicit Driver against active `b2b` Driver and active User joined rows *inside their existing database transaction* before any assignment/audit change. SQL row locking narrows the race with concurrent deactivation. Representative-only Van assignments continue to be supported. Ending a legacy invalid assignment is permitted so administrators are not trapped with an unsafe active record. The existing http route/controller/validation, effective-store scope, roles and assignment history remain unchanged.

Validation is localized with dedicated `van_assignment_guard` AR/EN strings, without editing the shared `field_operations.php` currently owned by another Van PR. The existing secure Dashboard form displays `driver_id` validation errors. No new endpoint/permission/Sidebar.

## Automated acceptance

`V2VanAssignmentDriverTypeWriteGuardTest`: positive active B2B creation/edit and representative-only assignment; Arabic and English failure for Retail, inactive Driver, inactive User and unknown ID; no VanAssignment/audit writes on rejection; update preserves old active assignment and audit; historical invalid assignment may be ended. Existing `VanRegistryServiceTest` must pass unchanged. Full Laravel/Pint/PHPStan/security, AR/EN localization and MySQL/Redis install/upgrade/recovery via exact-head Required CI; no local test success claimed until actual results.

Impact matrix: Backend/Dashboard Van assignment write protected. Van app: safer authoritative assigned-driver provenance, no Flutter/API payload change, B2B-only unchanged. Driver app: B2C-only execution unchanged, Retail driver may not acquire Van assignment. Customer app: unaffected. Finance, custody, routing, legacy assignment cleanup and broader Van visit/territory/offline parity remain under their original V2 issues, not certified by this bounded leaf.

Close #3433 only after the issue-specific PR passes exact-head Policy, V2 Isolation, Required CI child jobs, is reviewed and merged only into Foodex_V2. Parent #2738 and #2503 remain OPEN for remaining V2 contracts and final manual staging UAT.
