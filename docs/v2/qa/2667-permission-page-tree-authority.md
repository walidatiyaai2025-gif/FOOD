# V2 #2667 — P337 شجرة الصلاحيات والصفحات: canonical authority and metadata

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), P337. **Parent:** #2503. **Integration:** `Foodex_V2` only, never `main` or production.

## Placement and workflow

- **Domain:** Administration & Security. **Entity:** Role / Sidebar page / Permission Code.
- **Actor:** authorized `roles.manage` administrator; no Retail Store Admin, Driver, roleless or inactive actor may directly open this screen without the independent server grant.
- **User goal:** inspect the actual page/permission tree, including human name, stable page key, permission codes and ALL/ANY decision, role scope, B2B/B2C channel, and operational module readiness before a reviewed role change.
- **Workflow:** Security administration, infrequent privileged configuration; dense metadata page.
- **Existing parent:** Administration Hub → Sidebar Role Access → `admin.sidebar-access.roles` (`SidebarManagementController::roleAccess`).
- **Placement:** **REUSE** the existing role workbench with existing responsive permission matrix and effective preview. No second Sidebar entry, route, permission grant or role-editor policy.
- **Navigation type:** ADMIN_SETTINGS / DOMAIN_HUB child. The real GET is protected by `Gate::authorize('roles.manage')`. Fallback for unauthorised users is HTTP 403; no discoverable privileged UI.
- **Responsive/accessibility:** existing mobile-web layout collapses to one column below 1200px; 320/360/390/412/430px widths use existing FOODEX shell and 44px permission control rows. AR/RTL and EN/LTR are rendered from the canonical language catalog; labels remain bound to inputs and preview has live status and server-boundary explanation. Manual device/keyboard/screen-reader acceptance is deferred to master #2503 / UX #2750.

## Authorization and state contract

The role matrix is sourced from `AdminSidebarManager::roleMatrix` and canonical `backend/config/admin_sidebar.php`, not an independent authority. The workbench displays route/item name, code, permissions, global/store/both scope, B2B/B2C channel and module classification. `ModuleOperationalReadiness` is queried for module-gated nodes: exceptions fail closed to a non-ready preview status. A preview node, checkbox or saved cosmetic visibility can **never** grant a protected backend route or bypass source-owned tenant/store/channel/entitlement checks.

Role permission updates already submit to audited `admin.security.roles.update`, require an 8–500 character change reason and preserve unrelated permissions. Platform Owner, Super Admin and built-in protected roles remain read-only where canonical server rules require; no extra direct mutation endpoint is introduced. Existing #2800 / PR #3215 already proves audited save and protects immutable system roles. Existing #2888 / PR #3184 covers resulting Sidebar; neither implementation is duplicated here.

## Independent P337 automated acceptance

`backend/tests/Feature/V2PermissionPageTreeAuthorityTest.php` verifies:

1. An authorized Super Admin can inspect the existing B2C Store Admin page tree with role-scoped `b2c_orders` metadata (`orders.view`, scope `store`, channel `b2c`) and `erp_finance` tagged as module `finance`; both AR/RTL and EN/LTR return a real HTTP success with translated headings and five per-node field labels.
2. A protected Super Admin role renders the same page as a read-only preview; explicit server-boundary and module-readiness flags are present but no editable reason/save operation is shown.
3. B2C Driver and unassigned identities get direct HTTP 403 for the role tree, even with a guessed privileged role identifier.

This complements rather than repeats P049's mutation/audit regression. No backend runtime, shared Dashboard Blade, configuration, permission, migration, seeder, or customer/driver/van source was changed.

## Surface and mobile impact classification

| Surface | Impact | Reason |
| --- | --- | --- |
| Backend | **Tests only** | Existing role GET and server `roles.manage` authorization/permission matrix reused |
| Dashboard | **No source change** | Existing Administration role tree, translation, responsive and accessible primitives reused |
| Customer App | **Not affected** | No public Customer API, storefront, session or RBAC contract change |
| Driver App | **Not affected** | No driver wallet, B2C Driver-only assignment, API or Flutter change |
| Van App | **Not affected** | No Van B2B-only route, visit, identity, inventory or app API change |

## Acceptance and delivery gates

One issue-owned branch and PR, **base `Foodex_V2` only**. Require exact final PR SHA Repository Policy, V2 Isolation, FOODEX Required CI (full Laravel, PHP/Pint/PHPStan/security/OpenAPI, AR/EN, real MySQL/Redis fresh-install/upgrade/recovery, terminal `required-ci-gate`). Do not claim locally executed test success when only GitHub CI ran. Do not merge on queued/red checks.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under owner #2503 dated 2026-11-10. This test/QA slice does not certify production, staging, live account reconciliation or all 177 future ERP sidebar nodes. No `main`, release, tag, APK or production/cPanel mutation.
