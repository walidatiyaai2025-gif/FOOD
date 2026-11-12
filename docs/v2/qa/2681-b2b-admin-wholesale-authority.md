# V2 #2681 — B2B_ADMIN Wholesale authority / source T122R1

## Source and non-duplication decision

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Table 122, row 1, role **مدير أعمال الجملة (B2B_ADMIN)**; parent #2503.
- Reuse canonical `config/job_roles.php`, legacy-authoritative `config/permissions.php`, `BuiltInJobRoleCatalog`, `User::hasPermission`, `OperationalTenantScope` and `AdminNavigation`.
- No additional role, permission, route, admin page, Sidebar item or parallel authorization system. **Existing Dashboard** `/admin/b2b/dashboard` and order operations `admin.operations.orders.index` are the user-facing entry points; role is not a Platform Owner. Existing Wholesale Principal is source of organization/store context.
- Placement: **Reuse** canonical Wholesale Dashboard + orders workspace. Navigation type **SIDEBAR**, route owner existing Dashboard. Desktop, tablet and mobile-web share the existing responsive `admin._sidebar` and canonical shell; Arabic RTL and English LTR remain selected via locale, no new markup or focus behavior. Fallback/back is existing Dashboard route. Empty/no-permission/disabled accounts must not get orphaned navigation.

## Permissions and separation of duties

Canonical seeded role permissions are derived from `config('permissions.roles.B2B_ADMIN')`, not silently replaced with the newer descriptive `job_roles.roles.B2B_ADMIN.permissions` matrix. `B2B_ADMIN` is **global assignment scope, tenant business scope**, and has *no mandatory enterprise module*. This last fact does not waive per-route enterprise readiness, entitlement or maker/checker controls.

| Capability | Effective control |
| --- | --- |
| Wholesale order view/edit/manage/dispatch | `orders.view`, `orders.edit`, `orders.manage`, `orders.dispatch`; delegated operations only in Wholesale Principal |
| Wholesale catalog / price / inventory | `catalog.*`, `b2b.pricing.*`, `inventory.*` according to exact seeded codes |
| Wholesale Finance and reports | `finance.view`, `finance.manage`, `reports.view`, `reports.export`; **not** `finance.post` or `finance.approve` |
| Security / user-role management | No `security.view`, `roles.manage` or `users.roles.manage`; direct Security Center HTTP must return 403 |
| Store/channel crossover | No B2C store assignment; B2C routes/permissions for any other Retail store are denied even with a forged `store_id` or global user ALLOW override |
| Mobile execution identities | No `deliveries.b2c.execute`, `deliveries.b2b.execute` or `van.login`; Wholesale administration never becomes Driver or Van execution identity |
| Account state and explicit DENY | Inactive/roleless accounts denied; `user_permission_overrides.effect=deny` revokes inherited view and its Sidebar/HTTP path without editing shared role grants |

Module availability, product ownership, discount policy, finance ledger approvals, and protected order transitions remain independently checked by canonical services. A passing navigation check is **not** authority to execute a state-changing workflow. Business flows requiring an independent checker cannot be authorized solely from this role's legacy `finance.manage`.

## Executable regression

`backend/tests/Feature/V2B2bAdminWholesaleAuthorityTest.php` creates only isolated test users/retail stores and tests:

1. Canonical B2B role, synchronized seeded grants, AR/EN business names and absence of wildcard/Platform Owner/SoD permissions.
2. Real HTTP Wholesale Dashboard and Orders entry via authorized navigation in Arabic RTL + English LTR, Security Center forbidden.
3. Wholesale store grant vs two independent Retail stores; forged Retail store selection and global ALLOW cannot create a B2C grant.
4. Explicit DENY suppresses orders Sidebar and direct route, preserving role assignment/catalog access.
5. Inactive and roleless negative paths.

These are deterministic DB-backed tests with **no changes to application routes, auth policy, seeded data, migrations, or production users**. Required exact-head PR CI: Repository Policy, FOODEX V2 Isolation Gate, Backend PHP syntax/Pint/PHPStan, full Laravel suite, Arabic/English localization and MySQL/Redis fresh install/upgrade/recovery acceptance. Unaffected Flutter jobs skip by normal change detection.

## Customer / Driver / Van impact review

| Surface | Applicability |
| --- | --- |
| Backend | New tests only; no business logic/endpoint/permissions/roles altered |
| Dashboard | Existing Wholesale route and Sidebar verified; no frontend markup, JS, CSS, new controls, navigation mutations |
| Customer | No app code, auth API, pricing payload, credit or order lifecycle change; Customer stays under existing B2C/B2B identity policies |
| Driver | No app code, dispatch API or delivery changes; B2C DRIVER ONLY remains authoritative |
| Van | No app code, visit/consignment/dispatch API change; B2B VAN ONLY remains authoritative |

**Remaining acceptance boundary:** Test evidence on isolated V2 demonstrates the exact covered role/HTTP/tenant/SoD matrix; it does not prove current production/staging account assignments, delegated approval limits, live Warehouse ownership or every possible ERP workflow. Final cross-surface visual/device UAT is deferred under owner decision in #2503. Keep the issue **OPEN** if full T122R1 operational acceptance is not independently evidenced. Do not change `main`, release tags, APK distribution or production cPanel.
