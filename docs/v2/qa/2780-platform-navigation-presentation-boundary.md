# V2 #2780 / T028R03 — protected Action Center, Workspaces and Treasury navigation

## Source, placement and authority
- Source: FOODEX unified RBAC / ERP / Sidebar discussion (2026-11-09), `T028R03`; parent #2503. Target integration: **Foodex_V2 only**.
- Reuse the existing authorized Sidebar and destinations; no new route, screen, menu entry, model, API or migration.
- Business domains: `enterprise_action_center` (Overview / My Work); `enterprise_workspaces` (Overview / platform workspaces); `treasury_master` (Finance / Treasury master data).
- Navigation type: `SIDEBAR`; canonical resolver `AdminNavigation::groupsFor()` generates only legitimate routes. Layout and role-visibility preferences remain presentation-only.
- The three keys are protected against legacy `admin_sidebar_items.is_enabled=false`, disabled parent group and `admin_sidebar_role_visibility.is_visible=false`. The persisted old values are **not deleted or silently rewritten**; the live Role Matrix reflects effective visibility, not the obsolete preference. Existing `saveRoleVisibility` and `saveLayout` already force protected keys enabled for new writes.
- **No security bypass:** `AdminNavigation` still decides whether each target is eligible before `AdminSidebarManager::apply` runs. Protecting cosmetic visibility cannot synthesize a missing route or permission.

## Route / role / scope / action acceptance
| Surface | Canonical route | Legitimate projection | Server-side guard |
| --- | --- | --- | --- |
| My Work / Action Center | `admin.action-center.index` | Active global ERP role or qualified B2C store admin with retail scope | `AdminNavigation::canAccessMyWork`; workflow decision POST separately authorizes mutation and SoD |
| Enterprise Workspaces | `admin.enterprise-workspaces.index` | Active global platform role, excluding service dashboard profile | `EnterpriseWorkspaceComposer::availableWorkspaceKeys` + per-workspace composition authorization |
| Treasury master data | `admin.treasury.master.index` | Eligible platform/global role with finance permission | `OperationalTenantScope::allowedStoreIds('finance.view')`, explicit store 403/404; cashbox/bank mutations separately require finance.manage |

## Automated regression matrix
- Global B2B_ADMIN: baseline discoverability of all three, then legacy disabled item **and** group **and** negative role-visibility preferences. All three remain discoverable exactly once at the same canonical routes. Real authenticated HTTP must return 200 in Arabic/RTL and English/LTR; finance.view remains effective; the legacy rows remain intact.
- Retail B2C_STORE_ADMIN: even with forged positive role visibility, qualified retail My Work stays reachable, but platform Workspaces/Treasury links are not synthesized. Workspaces direct HTTP must deny; foreign B2B Treasury store selection must deny (403/404).
- Actor with no role: neither Sidebar links nor direct Action Center / Workspaces / Treasury HTTP access is granted. Persisted role permissions are not changed.
- Protected-role preference save: three values must remain `is_visible=true` while ordinary unprotected preferences continue behaving as configured.
- Existing regression coverage continues to govern excluded customer-service role profiles, inactive accounts, cross-channel routing, finance maker/checker, denial precedence and module entitlement/readiness. This change does **not** enable an ERP module or bypass readiness.

## Customer / Driver / Van impact classification
- **Customer:** no mobile source/API response schema, login, catalog, pricing, order, or customer-facing navigation change. Dashboard-only Sidebar projection; no linked app Issue required.
- **Driver:** no driver mobile source/API, GPS, delivery flow or B2C dispatch change. B2C = DRIVER ONLY unchanged.
- **Van:** no van mobile source/API, territories, visits, financial collections or B2B dispatch change. B2B = VAN ONLY unchanged.
- No mobile builds are represented as tested by this PR when their paths are untouched. No release, production, cPanel or main promotion.

## Exact-head verification and independent acceptance
- Required: Repository Policy, FOODEX V2 Isolation, Laravel tests, Pint, PHPStan, security checks, AR/EN localization, MySQL/Redis install/upgrade/recovery jobs and terminal `required-ci-gate`, all green on the **final PR HEAD** before merge.
- Independent isolated V2 staging browser QA still required: AR RTL / EN LTR, widths 320–1280, keyboard/tab/focus/screen-reader, actual allowed/denied Sidebar link destinations, organization/retail scope and finance module-readiness variants. Code/CI evidence alone is **not** evidence of this browser acceptance.
- Rollback: revert isolated PR if needed. There is no migration or production-data rewrite; legacy preference rows are retained.
