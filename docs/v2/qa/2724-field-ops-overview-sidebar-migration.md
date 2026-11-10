# V2 #2724 — Field Operations Overview migration and tenant scope

Source: FOODEX Unified RBAC/ERP/Sidebar discussion 2026-11-09 / T356R7. Parent #2503. **Only Foodex_V2 integration**. No main, deployment, release or production package.

## Placement and route authority

- Domain: Fleet and Field Operations; entity: field operations summary, B2B Van assignments, custody and routing; actor: authorized wholesale management and finance; goal: access real operational metrics and exceptions.
- Decision: **REUSE** the existing canonical Field Operations Overview, not a duplicate screen/route/key/group.
- Parent/navigation: existing field_operations Sidebar group, item field_ops_overview, registry config/admin_sidebar.php, projection AdminNavigation::groupsFor / AdminSidebarManager. Canonical route admin.field-operations.overview, Controller FieldOperationsController::overview, existing view admin.field-operations.
- Permission projection: one of field_ops.manage, drivers.b2b.view, drivers.tracking.view, customers.view, territories.manage, finance.view (subject to existing Dashboard admission). Controller is authority; Sidebar visibility is not a grant.
- Frequency: daily operational; data density: existing KPI cards, analytics/exception list, date/store filters.
- AR/EN and responsive: existing admin.field_operations.overview and admin.nav_groups.field_operations labels, HTML lang/dir, shared sidebar/mobile drawer, native form labels, keyboard/focus semantics, desktop/tablet/mobile breakpoints. No new markup or translation keys.
- Placement type SIDEBAR, domain Field Operations, fallback to existing authorized Field Operations dashboard.

## Reproduced defect and fix

The existing FieldOperationsDashboardService::build already computes authorized B2B store filtered KPIs. The visible **outstanding custody/collections** summary card used a separate unscoped SQL SUM across all Van custody accounts. It could include B2C Retail, unrelated Wholesale stores and stores not matching the selected store filter even when all other dashboard data was scoped.

The Controller's custody aggregate now applies **the same previously validated B2B allowed store IDs**, further restricted to selected store_id. If no B2B stores are authorized, return zero rather than an unbounded total. Keep actor_type=van, signed ledger semantics and existing finance.view permission. No Controller authorization grant, new route/menu, API contract, migration, financial write, data loss or change to role administration.

## Automated HTTP acceptance

New backend/tests/Feature/V2FieldOpsOverviewMigrationContractTest.php:
1. Existing registry group/key, real route, one visible canonical link and Finance persona.
2. Synthetic two B2B stores with 25+5 EGP custody and one B2C store with 90 EGP. FINANCE actor gets 30 across authorized B2B stores, 25 when selected first B2B store, never Retail 90. Real route 200, dashboard HTML, AR/RTL and EN/LTR.
3. Forged B2C store_id => HTTP 403.
4. Custom global finance.view actor with no recognized B2B tenant role gets zero custody and cannot forge a B2B store selection; HTTP 403.
5. Store-scoped RETAIL_OPERATIONS actor cannot view B2B Sidebar destination or open it by URL.

The signed ledger is read-only, no ERP module fabricated, B2B VAN ONLY and B2C DRIVER ONLY enforced.

## Impact matrix

| Surface | Impact |
| --- | --- |
| Backend/Dashboard | **Affected**: existing Field Ops Overview financial read SQL tenant filter and real route/navigation tests. |
| Customer Flutter | **Not affected**: no Customer API, role, customer order, pricing, route or screen contract changed. |
| Driver Flutter | **Not affected**: no B2C dispatch, wallet, collection endpoint, state, token or Flutter changes. |
| Van Flutter | **Not affected**: no B2B Van assignment, custody write, settlement, trip/offline API or app change. |

Parallel PRs #3063/#3064/#3068 own distinct AdminNavigation changes: this PR does not modify that shared file. Sidebar routing #2725 is separately owned as its own issue.

## CI and release constraints

Require current exact-HEAD Repository Policy, FOODEX V2 Isolation, AR/EN, PHP Syntax, Pint, PHPStan, Composer security, full Laravel regression, MySQL/Redis install-upgrade-recovery and terminal required-ci-gate green. Recheck mergeability after base moves. **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** per #2503 and #2750. Never claim manual UAT or deployment, never merge into main.
