# FOODEX V2 #2655 — P325 Restaurant Digital Menu real authority

## Source and Reuse → Extend → Compose → New Screen

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P325 under the Applications & Restaurant source domain, label **القائمة الرقمية**. Parent coordinator: #2503. This issue's one implementation branch/PR targets `Foodex_V2` **only**.
- Business domain: B2C restaurant operations. Entity: existing Smart Ordering Site / draft, published and scheduled menu revisions, category/item trees. User: authorized manager of an enabled Restaurant-profile B2C store. Goal: discover and open the real Digital Menu editor and publish/review workflow for their **own** restaurant only.
- **REUSE**: one existing `smart_ordering` Sidebar entry in `stores` (label `smart_ordering.nav_title`), canonical `admin.smart-ordering.index` (GET `/admin/smart-ordering/settings`). Digital Menu is the existing **PAGE_TAB** in shared `_smart-ordering-workspace-nav.blade.php`, under that Restaurant workspace. The tab uses `smart_ordering_menu.nav_title` and `admin.smart-ordering.menu.index` (GET `/admin/smart-ordering/menu`, `store_id`, optional `setup=menu`). Current renderer `SmartOrderingMenuAdminController::index` / `admin.smart-ordering-menu` performs actual site-scoped editing, revision resolution and menu business data—not a placeholder route. The `setup=menu` path reuses the existing five-step guided setup (menu, design, identity, review, publish). Fallback: existing Restaurant settings tab, sidebar Stores parent and browser Back.
- Placement rationale: an independent new Sidebar entry or second digital-menu controller would duplicate an existing authorized function, violate Reuse-first and open divergent permissions. This P325 leaf verifies *functional discovery via an authorized PAGE_TAB* and does not alter current business domain or claim that the source IA `Applications & Restaurant` grouping has been formally approved. Final holistic source IA/UX placement reconciliation belongs to master #2503 and #2750, which remain open; prior #2513 documents the existing Restaurant entry placement discrepancy.
- Responsive composition: existing admin shell and tab navigation; `smart-ordering-menu.blade.php` already defines responsive breakpoints and RTL/LTR root direction; no new fields, pages, CSS, JS or component source. No runtime visual/keyboard/device UAT is claimed in this test-only change.

## Exact permission/tenant/readiness contract

1. `AdminNavigation::restaurantSaasItem` shows at most one Smart Ordering link for an active authorized B2C Restaurant-profile store and a STORE-scoped enabled Smart Ordering Enterprise entitlement. It does not expose separate `digital_menu` or `smart_ordering_menu` sidebar links. A Retail ordinary-store manager, B2B-only actor, or disabled Restaurant SaaS site receives no restaurant Sidebar link.
2. Shared Restaurant workspace navigation emits the Digital Menu **tab only** when `smart_ordering.manage` is effective for the selected store. Mere `smart_ordering.view` (even with visual navigation to Settings) does not grant menu editing.
3. Server-side `SmartOrderingMenuAdminController::context()` uses `RestaurantSaasStoreAccess::allowedStoreIds(actor, smart_ordering.manage, request)`; this in turn applies role/global and store scoped ALLOW/DENY, B2C channel, Restaurant profile, entitlement and explicit requested `store_id`. The real menu GET returns 200 to an authorized actor, 404 for an owned manager requesting a foreign enabled restaurant, 403 for unauthorized, disabled-entitlement, nonrestaurant or B2B actors. Showing a link cannot grant any permission.
4. No ERP `enabled` state is assumed to certify unrelated POS, finance or analytics modules. Only the already-active Smart Ordering restaurant entitlement authorizes this page. Actual publish/write transitions retain their preexisting independent authorization and revision lifecycle.
5. Arabic/English localization and RTL/LTR are tested via the real settings and menu HTTP views. The same route and store context is used in both locales. Existing accessibility focus/tab semantics and mobile layout remain unchanged; manual a11y evidence is end-stage.

## Automated acceptance

New executable `backend/tests/Feature/V2DigitalMenuRestaurantAuthorityTest.php` covers:
- enabled restaurant manager: one Stores sidebar entry; no new menu key; existing authorized Digital Menu workspace tab from settings; actual menu HTML and view with AR/EN RTL/LTR and selected store.
- two entitled restaurants: own menu 200, forged other-store menu URL 404.
- existing store-role permission `smart_ordering.view` with explicit *store-specific* `smart_ordering.manage` DENY: settings stays readable but menu HTTP 403. User roles and override data exist only in `RefreshDatabase` test DB.
- ordinary Retail store or unentitled restaurant admin 403 and no restaurant entry; B2B global-role and roleless users 403 on direct menu URL.

Full adjacent coverage remains the existing `SmartOrderingMenuDomainTest`, `RestaurantSaasNavigationConvergenceTest`, `V2RestaurantOverviewEntryAuthorityTest`, `RestaurantSaasRetailStoreEntitlementTest`, plus mandatory exact-head Repository Policy, V2 Isolation, Laravel/PHP/Pint/PHPStan/security/AR-EN/MySQL Redis clean-install/upgrade/recovery and Required CI.

## Backend, Dashboard and Customer/Driver/Van impact matrix

| Surface | Classification | Grounding |
| --- | --- | --- |
| Backend/API | **No contract mutation** | Existing Laravel admin GET, restaurant scope and revision service exercised in new Feature tests; no API, schema, migrations, seed, permission catalog, queue or cache change |
| Dashboard | **Test-only / no UI source change** | Existing Smart Ordering Sidebar + Digital Menu workspace PAGE_TAB and real renderer; localized, responsive view is left unchanged |
| Customer Flutter | **Not affected** | No Flutter code or customer storefront, menus API payload, checkout, catalog, pricing, auth, push or deep-link contract changed |
| Driver Flutter | **Not affected** | No Driver app, B2C dispatch, proof, GPS, wallet or status API change; B2C remains DRIVER ONLY |
| Van Flutter | **Not affected** | No Van app, B2B routes, inventory, visits, settlement or fulfillment contract change; B2B remains VAN ONLY |

No new mobile impact Issue is necessary for *tests and documentation only*, because no app-consumed API, UI, authorization, order, invoice or business event was changed.

## PR, CI and closure protocol

Issue #2655 has one branch `test/2655-v2-digital-menu-restaurant-authority` and exactly one PR to `Foodex_V2`. No runtime/production-source changes. Confirm the exact latest PR SHA, mergeable state and **all required checks green**, including the final `required-ci-gate`, before any merge; fix failures on the **same** branch/PR. After verified merge, close this atomic code leaf with SHA and CI evidence if all P325 requirements are satisfied. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503; keep parent/master #2503 and final UX gate open.

**Never** merge to `main`, create tags/release artifacts, install on live cPanel or deploy to production absent a new formal written owner authorization.
