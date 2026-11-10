# V2 P170 — Separate Wholesale and Retail Sidebar terminology

- **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / paragraph P170.
- **Issue:** #2880; parent #2503; **only target:** `Foodex_V2`.
- **Placement:** Reuse existing `AdminNavigation::groupsFor`, `admin._sidebar`, the same `admin.b2b.module` and `admin.b2c.module` routes and existing translation catalogs. No new screen, menu location, permission, module gate or UI affordance.

## Bilingual naming correction

| Canonical Sidebar destination | AR | EN | Existing authority |
| --- | --- | --- | --- |
| `b2b_products` | إدارة منتجات الجملة | Wholesale Product Management | B2B-global `catalog.view`, `admin.b2b.module(products)` |
| `b2b_inventory` | إدارة مخزون الجملة | Wholesale Inventory Management | B2B-global `inventory.view`, existing authorized B2B workspace |
| `b2c_products` | منتجات التجزئة | Retail Products | Assigned Retail store `catalog.view`, `admin.b2c.module(products,store_id)` |
| `b2c_inventory` | مخزون التجزئة | Retail Inventory | Assigned Retail store `inventory.view`, `admin.b2c.module(inventory,store_id)` |

Only four existing labels are changed, via paired `backend/lang/ar/admin.php` and `backend/lang/en/admin.php` strings. Common shared page names (`catalog_management`, `customer_360`) are not rewritten into a false channel claim. Administrator-supplied custom bilingual layout labels remain subject to existing `AdminSidebarManager` governance and have not been modified.

## Functional and negative verification

The database-backed `V2ChannelSpecificSidebarLabelsTest` runs for Arabic RTL and English LTR with (1) a global B2B_ADMIN + assigned B2C RETAIL_INVENTORY mixed-role account, (2) a Retail-only account, (3) an active role-less account, and (4) a foreign Retail store. It verifies:

- Distinct translated labels for B2B vs B2C product/inventory destinations, including the actual HTML response using the existing authorized Sidebar.
- The same exact existing named route and scoped `store_id` for each node before and after a display-only change.
- Authorized direct HTTP 200 for B2B products and an assigned B2C store; HTTP 404 for a foreign store, HTTP 403 for cross-channel access, and no Sidebar privilege for role-less users.
- No local permission, role, module, entitlement, readiness, tenant, dispatch or audit effect from translated text. Authorization remains in the backend, not the Sidebar label.

## Application impact

| Surface | Impact |
| --- | --- |
| Backend | AR/EN translation labels only, plus focused feature regression |
| Dashboard | Visible B2B/Wholesale vs B2C/Retail labels distinguish scope while maintaining AR/EN RTL/LTR and shared responsive mobile/desktop web Sidebar |
| Customer app | No Flutter, API, identity, catalog payload or mobile routes changed |
| Driver app | No driver app, dispatch, delivery state or API contract changed |
| Van app | No Van app, B2B dispatch, route or API contract changed |

No new mobile Issue is required for an unchanged consumer contract. B2B remains VAN ONLY and B2C remains DRIVER ONLY. Existing legacy data, installed Laravel package graph, user settings, DB rows and uploads are untouched. Rollback of this change requires reverting the two translation changes, not a database migration.

## CI and remaining acceptance

From `backend/`: `php artisan test --filter=V2ChannelSpecificSidebarLabelsTest`, `vendor/bin/pint --test tests/Feature/V2ChannelSpecificSidebarLabelsTest.php`. Require exact-PR-head Repository Policy, FOODEX V2 Isolation, AR/EN localization, backend PHP/Pint/PHPStan, Laravel feature tests, and required MySQL/Redis install/upgrade/recovery acceptance before merging.

Physical-browser responsive focus, keyboard and overflow acceptance must be independently documented on isolated V2 staging where required (related #2867). Green template and HTTP tests do not claim device runtime verification. No main promotion, production tag, release, cPanel deployment or live account edits are permitted.
