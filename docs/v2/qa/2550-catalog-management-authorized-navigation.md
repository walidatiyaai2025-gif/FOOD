# V2 #2550 · P220 — Catalog Management canonical authorized destination

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / P220. Parent #2503. **Integration target:** `Foodex_V2` only.

## Route, business placement and permissions

- **Domain / entity:** Catalog & Pricing / catalog products and categories.
- **Actor and goal:** Authorized wholesale catalog manager navigates from Catalog group to Catalog Management; store-scoped retail manager uses the existing Products destination to reach its own catalog.
- **Reuse decision:** Existing `admin.catalog.index` handled by `CatalogManagementController@index` and existing Dashboard catalog-management Blade. Reuse existing `catalog_management` registry item in `backend/config/admin_sidebar.php` and the existing `AdminNavigation::groupsFor` projection; **no duplicate route/sidebar/page**.
- **Canonical navigation:** group `catalog`, key `catalog_management`, permission `catalog.view`, route `admin.catalog.index`, localized key `admin.catalog_management`. Retail channel destination: `b2c_products`, same canonical catalog surface with resolved store scope. Back path: Catalog group / Dashboard navigation.
- **Authorization:** Server-side catalog scope and active-account guards remain authoritative. A presentation flag, visible link, roleless state, other-store identifier, stale route or unready module cannot create an HTTP grant. No new permissions, entitlements, financial actions or release toggles.
- **UI composition:** Existing Laravel Admin shell and existing product/category tabs; desktop/tablet/mobile UI, loading/empty/error, keyboard and focus behavior unchanged; no new UI primitive is introduced.

## Automated regression

`backend/tests/Feature/V2CatalogManagementAuthorizedNavigationTest.php` validates:

1. An active B2B admin with `catalog.view` discovers Catalog Management in the business Catalog group, resolves its canonical URL, opens HTTP 200 and sees AR/RTL + EN/LTR labeling.
2. Roleless and inactive accounts do not receive the route and direct HTTP requests are 403, not just hidden.
3. A retail store admin discovers the channel-specific Products path, opens only the assigned store's catalog and receives 403 for another retail store; no product records are created or mutated by read attempts.

Existing `AdminCatalogManagementTest`, `RetailAdminCatalogAuthorizationTest` and `AdminNavigationAuthorizationAuditTest` cover CRUD, product scope and broader route regression. This issue owns two **new** test/docs files only; it does not overlap ongoing #2868 Sidebar authority, #2879 domain grouping or #2752 authorization-projection PRs.

## Per-application impact

| Surface | Classification and proof |
| --- | --- |
| Laravel Backend | Test-only addition exercising existing real HTTP route and canonical RBAC/store scope |
| Dashboard | Existing Catalog Management destination and existing AR/EN labels; no Blade/route/asset change |
| Customer | No impact: no app code, shared API contract, product publication or entitlement changes |
| Driver | No impact: no app route, identity, dispatch or B2C delivery change |
| Van | No impact: no Van app, B2B-only dispatch, routing or stock contract changes |

Preserve wholesale **VAN ONLY**, retail **DRIVER ONLY**, store isolation, financial maker/checker, upload/storage and existing customer data.

## Delivery evidence

Focused check: `cd backend && php artisan test tests/Feature/V2CatalogManagementAuthorizedNavigationTest.php`. Merge only after required exact-head Repository Policy, V2 Isolation, Laravel, localization, MySQL/Redis and `required-ci-gate` all pass. Issue may be closed for implemented scope after merge with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per latest #2503 owner decision. Final browser/device/UAT remains pending on V2 master. Never merge to `main`, tag, release or deploy to production.
