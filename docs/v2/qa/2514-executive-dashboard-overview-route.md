# V2 #2514 · P184 — Executive Dashboard under Overview, real authorized route

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P184. **Parent:** #2503. **Only PR target:** `Foodex_V2`.

## Canonical placement

The existing Executive Dashboard is a **read-only operational summary** owned by the existing Dashboard / Overview business domain. Reuse the canonical `AdminNavigation::groupsFor` group `overview` and its `b2b_dashboard` / `b2c_dashboard` entries. Do not introduce a second dashboard, role menu, or runtime endpoint.

| Field | Wholesale | Retail |
| --- | --- | --- |
| Business purpose | View wholesale executive KPIs and drilldowns | View own-store management dashboard |
| Current navigation key | `b2b_dashboard` | `b2c_dashboard` |
| Route | `admin.b2b.module` with `module=dashboard` | `admin.b2c.module` with `module=dashboard` and current store |
| Source | `B2bWorkspaceController@show` / existing workspace | Existing B2C workspace controller |
| Role/scope | Active authorized B2B user; company wholesale channel only | Assigned active B2C store administrator; one-store context |
| Placement | Existing Overview Sidebar group | Existing Overview Sidebar group |
| Pattern | Existing Executive Dashboard shell, KPI cards and canonical drilldowns | Existing Retail dashboard with store-scoped metrics |
| Permission / boundary | Valid B2B channel role, runtime active user; not a display toggle | Store-assigned role and store/channel authority; no B2B fallback |
| Back path | Existing Overview group and Dashboard navigation | Existing Overview group and assigned-store dashboard |

No independent `admin_sidebar_role_visibility` preference is treated as a route authorization. A missing, inactive, roleless, cross-channel or forbidden tenant user must not gain a Dashboard HTTP route merely by guessing its URL.

## Automated acceptance

`backend/tests/Feature/V2ExecutiveDashboardOverviewAuthorizationTest.php` exercises the existing real navigation and HTTP boundary: active B2B role sees the Overview `b2b_dashboard` entry in AR/RTL and EN/LTR, its real Laravel route loads HTTP 200, and the opposite B2C dashboard rejects that actor; active store-scoped B2C sees the Retail Overview entry and its own store Dashboard while B2B is forbidden; inactive and roleless accounts have no Wholesale executive shortcut and direct HTTP is 403.

Existing `AdminShellTest`, `B2bAdminWorkspaceTest`, and `DashboardDateRangeTest` retain the deep KPI/data, locale and operational behavior acceptance. **No new rendering, mutations, translations, action route, policies, data migration, API or app code** is introduced, so the contract's desktop/tablet/mobile-web UI remains the existing responsive surface; staging browser/keyboard/screen-reader and viewport acceptance is deferred to the final #2503 UAT gate.

## Per-surface impact

| Surface | Classification |
| --- | --- |
| Backend | New regression test invoking canonical Laravel routes; no runtime change |
| Dashboard | Existing Overview executive destination and AR/EN, RTL/LTR; no new screen |
| Customer app | Not affected: no mobile routes, shared endpoint, data models or notifications changed |
| Driver app | Not affected: no dispatch, identity, route or delivery contract changed |
| Van app | Not affected: no B2B van assignment, field-ops API or order lifecycle changed |

Wholesale **VAN ONLY** / Retail **DRIVER ONLY**, financial maker/checker, entitlement and store scope stay unchanged.

## Exit gate

Focused test: `cd backend && php artisan test tests/Feature/V2ExecutiveDashboardOverviewAuthorizationTest.php`. Review current Issue/PR lease, full exact-head Repository Policy, V2 Isolation, Backend/Pint/static analysis, AR/EN and MySQL/Redis release acceptance plus final `required-ci-gate`. Merge only to `Foodex_V2`; close the leaf with PR/SHA/CI links once green. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`; never merge to main, tag, release or production deploy.
