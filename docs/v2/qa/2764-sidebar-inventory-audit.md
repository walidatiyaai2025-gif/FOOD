# V2 #2764 — source-to-current Sidebar audit (T015R06)

## Historical source and ownership
- Parent: #2503; isolated target: `Foodex_V2` only.
- Original T015R06 source reports **13 business groups / 61 Sidebar items / 25 disabled entries**.
- This is an archived reference, **not** a hard-coded expected current production state.
- No main, release, tags, production deployment, or modification of current user data.

## Placement and real navigation
- Domain: Administration / Sidebar organization; entity: canonical sidebar groups, entries and saved cosmetic visibility.
- Actors: administrators possessing `roles.manage` only. `Gate::authorize('roles.manage')` protects the actual HTTP route before data access; unauthorized direct GET/PUT returns 403.
- User goal: inspect current configured Sidebar counts and compare to the recorded source before editing or migrating layout.
- Placement: **EXTEND**, not a new page or a fake alternate authorization system. Existing Administration Hub Sidebar organizer, `admin.sidebar-layout.index`, `SidebarManagementController::layout`, `AdminSidebarManager::layoutData` and `admin/sidebar-layout.blade.php` are reused.
- Navigation type: existing `ADMIN_SETTINGS` entry, same route/permission/active group on desktop and mobile; no additional Sidebar node or route.
- Behavior: read-only comparison above the existing layout form; same AR/EN labels, RTL/LTR, logical direction, keyboard semantics and responsive stack. All output is loaded on the server and has an explicit unavailable state if configuration storage is missing.

## Counting semantics
1. **Registered groups/items**: count of canonical registered keys projected by `layoutData()`, incorporating only known saved group/item configuration, not duplicated disabled records.
2. **Disabled groups/items**: count `is_enabled=false` only after normalizing protected keys to effective enabled state. Old conflicting rows remain in the database, unchanged.
3. **Role-specific hidden rows**: count `admin_sidebar_role_visibility.is_visible=false`, including overlaps with global disables or unknown/ineligible role contexts; each row is a saved presentation preference, not a distinct page or denial.
4. **Recorded disabled display settings**: sum of disabled groups, disabled items and role-specific hidden rows. This is a transparent reconciliation convention, not proof that each of the historical 25 entries had an identical meaning.
5. **Differences**: numeric current-minus-historical deltas for 13/61/25; no mutation or automatic enablement to force parity.
6. **Missing storage**: return null instead of silently claiming zero saved hidden rows; show a localized unavailable status.

## Authorization, safety, failure and historical data
- This audit does not mutate role permissions, account assignments, store/channel scope, tenant context, module entitlements, finance SoD, protected keys or audit history.
- The effective Sidebar remains a projection of `AdminNavigation` and the server's real route/action Gate, module readiness and tenant/store/channel checks. A hidden preference must never grant or revoke HTTP access.
- Tests with real Laravel database rows cover negative direct-route 403, saved disabled flags, protected normalized flags, overlapping role hide counts, read-only persistence, no new audit records, and AR RTL / EN LTR.
- Current running installations may have more than 13 groups or 61 items; source totals are deliberately historical and not CI hard gates.
- **Outstanding source-level proof**: mapping the 25 disabled records (and 13/61 source entries) to exact historic export identifiers/current database keys requires authorized access to the original export and/or persisted instance data. This PR does not claim that this row-by-row forensic reconciliation occurred; do not close the Issue solely on numeric view evidence if that mapping is in scope.

## Cross-app impact classification
- **Customer**: no mobile/API/account/commerce/identity/notification routes or screens changed. Existing B2B/B2C checks unchanged.
- **Driver**: no driver login, dispatch, proof, collections, app code, or API changed. B2C DRIVER ONLY remains.
- **Van**: no van login, assignment, trip, selling, territory, offline, or API changed. B2B VAN ONLY remains.
- No new mobile impact Issue is necessary because the work is an authorized read-only Dashboard projection; no shared app contract changed.

## Acceptance evidence
- Dedicated `backend/tests/Feature/V2SidebarLayoutInventoryAuditTest.php`: current and saved rows; correct reference and count deltas; protected settings; direct-route 403; no mutation; AR/EN.
- Existing `AdminSidebarManagementTest` covers saved presentation config and canonical auth behavior.
- Required exact-head checks: Repository Policy, V2 Isolation Gate, Laravel feature tests, Pint, PHPStan, AR/EN, MySQL/Redis install-upgrade-recovery, final required-ci-gate.
- Manual owner/browser/device/UAT acceptance is `DEFERRED_TO_V2_FINAL` under #2503, but automated CI must still pass before any merge.
