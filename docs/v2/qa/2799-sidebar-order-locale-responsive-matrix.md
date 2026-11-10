# V2 P047 — Sidebar AR/EN × mobile/desktop × canonical order matrix

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph P047; Issue #2799; parent #2503.
- Ownership branch: `test/2799-v2-sidebar-order-locale-responsive-matrix`; **PR base `Foodex_V2` ONLY**.
- Reuse existing `AdminNavigation::groupsFor`, `AdminSidebarManager::apply`, `admin._sidebar` and `admin._sidebar-node`. No new menu/screen, independent client-side authorization policy, UI source edit, role/permission assignment, schema change or app endpoint.

## Acceptance matrix

| Actor/condition | AR RTL / EN LTR | Ordering | Direct-route outcome | Web presentation |
| --- | --- | --- | --- | --- |
| Global B2B_ADMIN with authorized catalog | Both locales | Configured Catalog before Overview and B2B Products before Catalog Management | B2B dashboard/products and catalog: 200 | One generated `data-foodex-nav` tree and same item URLs for desktop/mobile responsive CSS |
| Global B2B_ADMIN without `roles.manage` | Both locales | Same allowed catalog ordering | Direct `admin.sidebar-access.roles`: 403 | No unauthorized roles management link |
| Active unprivileged account without roles | Both locales | Enabled/reordered Catalog cannot appear without RBAC | Direct catalog and B2B products: 403 | No unauthorized catalog keys |

A pair of independent `admin_sidebar_groups` rows and `admin_sidebar_items` rows is configured in disposable `RefreshDatabase` fixtures using extreme sort-order values. The test checks the **resulting canonical key order** and **actual server-rendered nav HTML** rather than merely re-sorting the expected output. It asserts custom Arabic and English group labels and the RTL/LTR HTML language/direction. Equal ordered route sets across languages mean translation does not silently reshuffle or grant navigation. The mobile drawer shares the one authorized HTML nav tree; it does not have an alternate permission allowlist.

Actual browser device viewport, keyboard focus trap, geometric overflow and pointer behavior remain a separately required staging acceptance: `#2867 / P145`. Static responsive stylesheet assertions here **do not** prove those runtime behaviors. Do not close #2799 if that acceptance is required and lacks evidence.

## Authority and cross-surface impacts

| Area | Impact / boundary |
| --- | --- |
| Backend | **Test-only**: no PHP application service, route, schema, RBAC or permission behavior changed |
| Dashboard | **Test-only**: asserts existing authorized layout order and AR/EN rendered HTML; no user-facing markup change |
| Customer app | Not affected: no Flutter files, API, identity/payload or mobile Customer navigation contract changed |
| Driver app | Not affected: no driver API, assignment, dispatch, Flutter or delivery contract changed |
| Van app | Not affected: no wholesale/Van API, dispatch, Flutter or delivery contract changed |

No new mobile Issues are needed because no API or app contract is altered. Wholesale dispatch remains Van-only, and Retail delivery remains Driver-only. This QA lane does not mutate financial maker/checker, supplier, credit, delivery or account state, nor does it supersede their security tests. Store/role HTTP matrix is owned by #2790 and is not reimplemented in P047.

## Validation and handoff

From `backend/`: `php artisan test --filter=V2SidebarOrderLocaleResponsiveMatrixTest`, followed by `vendor/bin/pint --test tests/Feature/V2SidebarOrderLocaleResponsiveMatrixTest.php`, PHPStan, AR/EN localization, Repository Policy, V2 Isolation and exact-head Required CI (including MySQL/Redis install-upgrade-recovery). Record run URLs, last PR head and merge SHA in #2799. If the test reveals a broken inherited Dashboard contract, fix only on this Issue branch after rechecking worker file leases; never weaken a failing assertion to green it.

- No main merge, production cPanel deployment, version promotion, tag or release authorized.
