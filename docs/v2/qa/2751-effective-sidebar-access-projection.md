# FOODEX V2 #2751 — P006 Sidebar projects *effective* access

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph `P006`, parent [#2503](https://github.com/walidatiyaai2025-gif/FOOD/issues/2503). PR integration is exclusively `Foodex_V2`; no `main`, release, deployment, or production test is implied.

## User outcome and existing authoritative implementation

The user sees an administration Sidebar corresponding to work they **already have server permission to perform**, not a second privilege-management system. No duplicate menu, API, RBAC or route is introduced. The existing chain is:

1. Laravel-authenticated actor with active account and active roles.
2. `AdminNavigation::groupsFor($actor)` establishes candidates from real role permissions, explicit DENY/ALLOW, business channel, tenant/store scope, module availability and readiness.
3. `AdminSidebarManager::apply` may cosmetically hide, label, reorder and group *authorized* candidates; a saved `is_visible=true` or enabled item cannot create an unauthorized HTTP route.
4. Direct Laravel HTTP endpoints continue to enforce real permissions regardless of cosmetic hides.

This is an **authorization regression only**, not a new UI or RBAC policy. Protected governance shortcuts and Platform Owner diagnostic inventory must still honor canonical permission and account/tenant constraints.

## Security acceptance matrix

| Persona/condition | Sidebar decision | Real HTTP outcome |
| --- | --- | --- |
| Assigned Retail Store A admin, saved `is_visible=true` for Wholesale inventory/ERP Finance/Sidebar governance | Forbidden wholesale/global-only keys absent | B2B inventory and Sidebar governance return 403 |
| Retail A admin, `/admin/b2c/inventory?store_id=A` | Authorized Retail workspace | 200 (AR RTL, EN LTR) |
| Same actor, forged Retail B or Wholesale store ID | No new grant | 404, no record disclosure |
| Wholesale B2B_ADMIN with `catalog_management` cosmetically hidden | Catalog entry suppressed | Real authorized `admin.catalog.index` remains 200 |
| Same actor, B2B role deactivated after a persisted `is_visible=true` | Catalog entry disappears | Catalog and B2B Dashboard return 403 |
| Roleless or inactive actor | No protected business access | Catalog returns 403; inactive returns no Sidebar groups |

The test uses seeded **synthetic identities and stores only**. It never exports, deletes, reassigns or logs real account data. No finance mutation is made. Explicit financial SoD and ERP entitlement/readiness remain enforced by existing guards and are not replaced by Sidebar checkboxes.

## Per-app impact: Customer / Driver / Van

- **Customer:** No Flutter code, API endpoint, payload schema, customer identity, checkout, pricing, invoices or notification contract changes.
- **Driver:** No driver login, dispatch, wallet, notification, status-transition, assignment or Flutter changes; **B2C → DRIVER ONLY** remains unchanged.
- **Van:** No van login, visits, consignment, routing, assignment or Flutter changes; **B2B → VAN ONLY** remains unchanged.

All three app impacts are **not affected by these additive Dashboard Laravel tests**. Therefore no additional app issue is created for this PR; broader P006/parent V2 end-to-end acceptance still requires separate app/HTTP contract coverage.

## Evidence and explicit scope boundary

New `backend/tests/Feature/V2SidebarEffectiveAccessProjectionTest.php` exercises four independent tests with real Laravel HTTP assertions and AR/EN direction where rendered. Verify with:

- `php artisan test --filter=V2SidebarEffectiveAccessProjectionTest`
- `vendor/bin/pint --test tests/Feature/V2SidebarEffectiveAccessProjectionTest.php`
- Exact-head required GitHub Actions: Repository Policy, V2 Isolation Gate, PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN localization, Laravel complete tests, MySQL/Redis fresh install/upgrade/recovery, terminal required-ci-gate.

**P006 full acceptance:** Even after green CI and merge, keep #2751 OPEN unless the full authorized-route/module/entitlement/maker-checker/tenant/mobile matrix is verified, and any remaining UI projection defects are resolved. Test-only evidence for one route family does not certify all screens or a production deployment.
