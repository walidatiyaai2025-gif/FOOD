# V2 #2792 — P040 Sidebar layout is not route authority

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P040 / native DOCX P035; parent #2503. Isolated integration: **Foodex_V2 only**.

## User goal and placement

- Domain: Dashboard Catalog / Administration Sidebar Layout. Entity: authorized Catalog Management route and its presentation registry node.
- Actors: wholesale B2B_ADMIN for authorized catalog access, global settings-only delegate without catalog grant.
- Existing route: `admin.catalog.index`; canonical Sidebar key `catalog_management`, group `catalog`. Permission `catalog.view`, scoped by existing `CatalogManagementController` and management middleware. Dashboard B2B/B2C context remains server-authoritative.
- **Reuse** the existing permission Gate, `AdminNavigation::groupsFor`, and `AdminSidebarManager::apply`. No duplicate auth policy, new route, screen, translation or frontend module.
- Layout flags (`admin_sidebar_items.is_enabled`, `admin_sidebar_groups.is_enabled`) are presentation controls, not access-control grants/revocations. A disabled link is hidden, while a user with unchanged effective catalog rights can still access the canonical route directly; a re-enabled link never bypasses missing permission.
- An inactive module or revoked entitlement remains independently subject to existing module guards; these tests do not pretend that enabling layout makes an unavailable module operational.
- The Admin Hub, protected Sidebar governance items, any financial maker/checker policy, and auth/tenant/mobility invariants are unchanged.

## Regression evidence

`V2SidebarLayoutVsRouteAuthorizationTest`:
1. For B2B Admin in AR/RTL and EN/LTR, `catalog.view` makes `catalog_management` visible and HTTP GET Catalog 200. Setting its canonical layout item `is_enabled=false` removes the link, **not** the underlying authorized route; HTTP remains 200.
2. The same B2B actor cannot open the B2C dashboard (HTTP 403), regardless of layout. Restoring the item flag restores the link.
3. Disabling the entire Catalog group hides its entries without cancelling the valid catalog read; restoring group visibility restores the authorized entry.
4. Even an explicitly enabled layout item is not exposed for a custom global settings-only role with no `catalog.view`, and a forged direct GET still gets HTTP 403.
5. The existing `AdminSidebarManagementTest`, `AdminNavigationAuthorizationAuditTest` and responsive/locale contracts provide surrounding navigation and a11y coverage; no UI markup or copy changes here.

Test on real seeded Laravel database (test-isolated). No change is applied to live Sidebar layout or production role records. Required: focused and full Laravel suites, PHP syntax/Pint/static/security, AR/EN parity, MySQL/Redis fresh-install/upgrade/recovery acceptance, and exact-head green Repository Policy, V2 Isolation and Required CI Gate.

## Impact matrix

| Surface | Impact | Reason |
| --- | --- | --- |
| Laravel Dashboard / Backend | Regression coverage | Existing Sidebar layout and true HTTP authorization, no runtime changes |
| Customer Flutter | Not affected | No shared route/API/data/auth change |
| Driver Flutter | Not affected | No Driver assignment/location/API/auth change |
| Van Flutter | Not affected | No Van dispatch/territory/visit/API/auth change |

No mobile follow-up issue is needed for this test-only work. B2B VAN-only, B2C DRIVER-only continue unchanged.

## Delivery fence

One Issue #2792, one `feat/2792-v2-sidebar-layout-vs-authorized-http` branch, one PR targeted only at `Foodex_V2`. No `main` push, PR, merge, tag, cPanel, APK or release. Close only after tests and integration confirmed. Master #2503 remains OPEN.
