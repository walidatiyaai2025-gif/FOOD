# V2 #2656 — Restaurant QR navigation and HTTP authority (P326)

- **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, legacy P326 / Applications & Restaurant; parent #2503.
- **Integration:** `Foodex_V2` ONLY, issue-scoped branch `test/2656-v2-restaurant-qr-sidebar-http-contract`; no main, deployment, release or production changes.
- **Ownership:** https://github.com/walidatiyaai2025-gif/FOOD/issues/2656#issuecomment-6779232198
- **Decision:** Reuse the existing Restaurant SaaS/Smart Ordering QR management page and its existing workspace navigation tab. Do not add a new route, menu group or QR engine.

## Placement / route authority

| Attribute | Decision |
| --- | --- |
| Domain, entity, goal | Restaurant SaaS / dynamic QR records / manage and print restaurant entry codes |
| Actor, business context | Authenticated B2C restaurant store operator with effective per-store QR permission; Super Admin requires explicit support-access scope; B2B operators never inherit restaurant rights |
| Workflow / frequency / density | Repeated operations on per-store QR records; paginated list with filter/create/edit/toggle/asset actions |
| Existing parent | Smart Ordering workspace; existing `admin._smart-ordering-workspace-nav` horizontal tabs |
| Placement / primary pattern | REUSE existing page-level `PAGE_TAB`, operational management grid; no new screen or Sidebar item |
| Canonical GET | `admin.smart-ordering.qr.index` → `SmartOrderingQrController::index` → `admin.smart-ordering-qr` |
| Navigation owner / entry | `AdminNavigation::restaurantSaasItem` opens Smart Ordering; `admin._smart-ordering-workspace-nav` contains the QR tab |
| Permissions | `smart_ordering.qr.view` for list/SVG/PDF; `smart_ordering.qr.manage` for create/update/toggle/rotate |
| Scope / module | `OperationalTenantScope`, `RestaurantSaasStoreAccess`, RESTAURANT business profile, live `smart_ordering` store entitlement and module lifecycle |
| Selected-context behavior | QR tab is shown only when effective `smart_ordering.qr.view` is allowed for the selected `store_id`; another store's permission never produces a dead link |
| Other-store / disabled | Direct query to unowned/explicitly denied selected store returns 404; no authorized restaurant stores or disabled entitlement returns 403; assets and mutations re-check scope |
| Back path / mobile | Existing Smart Ordering tabs retain store context; standard Dashboard sidebar becomes mobile drawer; tabs use current `foodex-tabs` responsive treatment |
| Localization / accessibility | Existing `smart_ordering_qr` AR/EN translations, RTL/LTR HTML direction, `aria-current` active tab, keyboard-focusable native links, shared 44px controls |
| State | Existing paginated loaded/empty/error views; existing QR enabled/disabled, signed public token and printable asset protections unchanged |

## Verified source defect and focused fix

The shared Smart Ordering workspace tab previously called `AdminNavigation::hasPermissionInScope('smart_ordering.qr.view')` across *any* Retail store. An operator entitled to Restaurant A but explicitly denied QR viewing at Restaurant B would see the QR tab while browsing B. The actual GET for B correctly returned 404. The tab now evaluates `User::hasPermission('smart_ordering.qr.view', $restaurantStoreId)` when a store is selected, consistent with neighboring workspace tabs. No controller/authorization grant was weakened; direct HTTP and asset/mutation gates remain authoritative.

## Test matrix (new Feature/V2RestaurantQrSidebarContractTest.php)

1. Authorized restaurant store operator: visible canonical Smart Ordering entry and QR tab, QR page AR/RTL and EN/LTR, valid list limited to owned restaurant.
2. Multiple assigned stores: explicit per-user store DENY hides only the denied-store QR tab, permits the allowed-store tab, and denies direct denied-store GET; global DENY prevents direct GET.
3. QR VIEW without QR MANAGE: QR remains viewable but no create action is displayed; direct create/toggle requests are rejected and QR records remain unchanged.
4. Disabled Restaurant SaaS entitlement and wholesale operator: no restaurant navigation authority and no direct QR page access.

Existing tests `SmartOrderingQrTest`, `V2RestaurantOverviewEntryAuthorityTest` and `AdminNavigationAuthorizationAuditTest` remain relevant. No independent Sidebar visibility permission is introduced.

## Backend / Dashboard / Customer / Driver / Van

- **Backend:** Existing controller, routes and service permissions preserved. Added HTTP regression tests only; no API shape or data migration.
- **Dashboard:** Corrected selected-store QR tab; canonical page, localized labels, shared design system, page layout and assets preserved. Feature tests verify AR/EN and RTL/LTR. Browser/device-width manual evaluation remains part of final V2 UX gate.
- **Customer app:** No Flutter or API contract change. Public `/q/{token}` QR resolution and Customer storefront/order lifecycle are unchanged; no linked mobile implementation issue required.
- **Driver app:** No order dispatch/status/location or API contract change; B2C→DRIVER-only remains unchanged.
- **Van app:** No B2B field workflow/API contract change; B2B→VAN-only remains unchanged.

## Acceptance

- Required exact-head GitHub CI and reviewer evidence must be green before merge; release/production installation is excluded.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per owner update on #2503. Do not claim staging-browser/device proof from a unit/feature test.
