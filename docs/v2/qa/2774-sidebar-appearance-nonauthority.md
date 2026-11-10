# FOODEX V2 #2774 — Sidebar appearance is not an authorization policy (P023)

**Target:** `Foodex_V2` only. **Source:** Unified RBAC/ERP/Sidebar 2026-11-09, P023, master #2503. **Scope:** independent security regression of the existing `AdminNavigation` → `AdminSidebarManager::apply` presentation layer and real Laravel HTTP authorizers.

## Security boundary and implementation

The legacy database tables `admin_sidebar_groups`, `admin_sidebar_items` and `admin_sidebar_role_visibility` describe display grouping, ordering, labels and visible presentation choices. They must not determine server-side access, add a user permission, enlarge a store/channel scope, bypass finance segregation of duties, or grant any route simply by changing `is_visible` / `is_enabled`.

The existing `AdminNavigation::groupsFor` establishes authorized items through canonical role, scope, channel and module logic **before** `AdminSidebarManager::apply`. Protected canonical shortcuts can remain visible despite cosmetic hides. For ordinary, nonprotected links an administrator may hide the menu entry, but direct authorized HTTP access must still follow the real controller/permission guard (not the cosmetic setting). Conversely, marking privileged entries visually visible never grants routes withheld by the real authorizer.

## Automated source-boundary acceptance

`backend/tests/Feature/V2SidebarAppearanceNonAuthorityTest.php` adds two new isolated `RefreshDatabase` tests:

1. A global `B2B_ADMIN` holds real `reports.view`. Rows for `analytics`, `reports_center`, and that role's item visibility are all disabled. The regular menu link disappears but direct `admin.reports.index` remains HTTP 200 in both AR RTL and EN LTR; no permission is newly minted or revoked and the legacy appearance rows remain as cosmetic evidence.
2. An assigned `B2C_STORE_ADMIN` is deliberately given positive **visual** rows for `erp_center`, `treasury_master`, and `sidebar_role_access`. None becomes an authorized Sidebar item, and real platform ERP, role editor and wholesale dashboard routes return HTTP 403 in both locales. The assigned B2C dashboard remains 200 for its own store; a foreign store request yields concealment-safe HTTP 404. A roleless actor cannot enter role management.

This suite deliberately uses existing seeded permissions, real Laravel routes, role assignments and store-scoped authorization. It does not mock a grant, change any runtime role or group tables, or bypass actual HTTP controllers. Existing broader tests such as `V2PlatformNavigationPresentationBoundaryTest` and `AdminNavigationAuthorizationAuditTest` remain authoritative; P023 isolates the **DB appearance versus HTTP security** invariant.

## Impacts and negative cases

| Surface | Change / impact |
| --- | --- |
| Backend | Two security-oriented Laravel Feature tests only; no route, API, migration, permission, audit or SoD mutation |
| Dashboard | AR/EN and RTL/LTR checks of existing routes and menu projection; no production Blade, JS or styling change |
| Customer | Not affected: no Flutter, customer auth, cart, order or public API contract change |
| Driver | Not affected: no Flutter, dispatch/GPS/collection route change; B2C remains DRIVER ONLY |
| Van | Not affected: no Flutter, route/wholesale/collection changes; B2B remains VAN ONLY |

The tests verify actual negative HTTP 403, store-concealing 404, real 200 authorized routes, roleless denial, and that cosmetic rows survive without becoming privilege. No confidential user, server or finance data is written; test data are synthetic and transactionally isolated. No live cPanel/production access is claimed.

## Integration, CI and acceptance

Use **one issue branch**, one PR into `Foodex_V2` and all required **exact final HEAD** checks: Repository Policy, V2 Isolation, backend syntax/Pint/PHPStan/security/OpenAPI, full Laravel, AR/EN and MySQL/Redis fresh install, upgrade and recovery, with terminal green `required-ci-gate`. Repair any failure on the **same** PR; do not merge a pending, skipped-required or red head.

`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per owner #2503 for real browser, full responsive/keyboard a11y and integrated production-like UAT. Only the P023 implementation leaf may close when its source scope, exact-head CI and isolated merge are verified; full V2 program and final installation/release gates remain open. Never promote, merge or push to `main`, publish releases/tags or deploy production without explicit owner instruction.
