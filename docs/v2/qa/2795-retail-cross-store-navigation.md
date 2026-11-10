# V2 #2795 — Retail cross-store navigation and direct-route authorization

## Canonical authority and placement
- Domain: Retail management; entity: Store; actor: store-scoped Retail worker.
- Reuse the existing management Sidebar and `admin.b2c.module` -> `B2cWorkspaceController::show`. No new route or screen.
- Navigation owner: `AdminNavigation`; HTTP authorization: `TenantContextResolver::retail` plus module permission checks.
- Sidebar selects an assigned active Retail store where the actor actually has the module permission. This is navigation context, never an authorization grant.
- Tenant isolation on direct forged workspace URLs remains HTTP 404 (anti-enumeration); forbidden Catalog/Wholesale access remains HTTP 403.
- No live role changes, database migrations, production data writes, new secrets, or deployment.

## Regression and security acceptance matrix
| Actor / request | Expected |
| --- | --- |
| Store 2 RETAIL_OPERATIONS plus Store 3 RETAIL_INVENTORY: inventory/products Sidebar | Links bind Store 3 with permission |
| Same worker: reports Sidebar | Link binds Store 2 with permission |
| Same worker: authorized Sidebar destination HTTP | 200 with the selected store |
| Store 2 role requesting Store 3 workspace (store_id or store) | 404 |
| Store 2 role requesting Store 3 catalog | 403 |
| Retail actor requesting Wholesale module | 403 |
| Unassigned Store 4 | No generated Sidebar link |
| Arabic RTL / English LTR | Same canonical responsive Dashboard shell and store selection, no new UI labels |

## Mobile and responsive impact
- Customer Flutter: none; no changes to API, customer authorization, app routes, or deep links.
- Driver Flutter: none; no changes to Driver authentication, assignments or execution routes.
- Van Flutter: none; no changes to Van auth, assignment or delivery routes.
- Dashboard: only existing server-generated Sidebar route parameters; original keyboard, mobile drawer, AR/EN labels and responsive rendering preserved.

## Verification
- `php artisan test --filter=V2RetailCrossStoreNavigationTest`
- `php artisan test --filter=B2cTenantWorkspaceIsolationTest`
- `php artisan test --filter=RetailStoreAdminSurfaceAuditTest`
- `php artisan test --filter=AdminNavigationAuthorizationAuditTest`
- `vendor/bin/pint --test app/Support/AdminNavigation.php tests/Feature/V2RetailCrossStoreNavigationTest.php`
- Exact-head required GitHub CI must pass before merging only to `Foodex_V2`. Do not close the Issue before integration/acceptance.