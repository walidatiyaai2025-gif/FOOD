# FOODEX V2 #2506 — General Overview dashboard: mixed B2B/B2C contexts

**Source:** \`FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx\`, Part 2 §4, P176, parent #2503. This work complements P181/#2511 (Wholesale) and P182/#2512 (Retail); it does **not** invent a third global dashboard.

## Placement / business workflow

- **Domain:** Overview. **Entity:** channel-appropriate dashboard. **Actor:** B2B Administrator with an independently assigned Retail Store Admin role, or a restricted single-channel user. **Goal:** navigate to the right business dashboard without losing tenant context or creating duplicate global screens.
- **Parent:** \`AdminNavigation::groupsFor\` \`overview\`. The canonical child destinations are \`b2b_dashboard\` → \`admin.b2b.module(module=dashboard)\` and \`b2c_dashboard\` → \`admin.b2c.module(module=dashboard,store_id=authorized-retail-store)\`; both reuse existing controllers and real dashboard services.
- **Placement:** reuse existing authorized Sidebar children and corresponding responsive drawer, rather than adding a new menu item, route, tab, or page. Single-channel actors see only their authorized destination. A genuinely mixed-role actor sees exactly one legitimate link per channel; no implicit elevation or cross-channel fallback.
- **Permission and scope:** B2B is server-guarded by wholesale tenant resolution and independently assigned global role. B2C is server-guarded by retail-store role assignment and explicit store context; a foreign store is HTTP 404. A missing-role account has no channel permission even when forging direct URLs (403). Inactive retail stores cannot be selected (404).
- **Module/entitlement/readiness:** these are the already-live Dashboard modules, with no ERP operational-readiness grant; individual dashboard widgets retain existing module permissions and tenant data filters. No code change to module registry, feature flags, contracts or stored Sidebar configurations.
- **Localization:** existing AR RTL \`إدارة الجملة\` + \`لوحة التحكم\`; EN LTR \`B2B Wholesale\` + \`Dashboard\`. They are contextual labels; a generic title never means a universal data scope.
- **Fallback/states:** on missing authority, deny with HTTP 403/404, not a fake landing page. Authorized empty/loaded states come from the existing dashboard services, not synthetic production records. Screens remain fully responsive on the original layouts.

## Tests / acceptance

\`backend/tests/Feature/V2OverviewMultiChannelDashboardAuthorityTest.php\` verifies an actual mixed-role actor in both locales: one wholesale and one retail Sidebar link, exact stable routes and scoped params, rendered UI \`navContext\` and RTL/LTR, HTTP 200 for both legitimately authorized dashboards, foreign Retail store 404, no-role actor HTTP 403 and inactive store 404. This is independent from the already merged channel-specific tests in #2511 and #2512.

Pass exact-head Repository Policy, V2 Isolation, AR/EN localization, PHP syntax/lint/Pint/PHPStan and security audit, Laravel full test suite, MySQL/Redis fresh install/upgrade/recovery, and final required-ci-gate before merging to \`Foodex_V2\`. This PR adds **test and QA documentation only**. If any failure occurs, fix this same issue branch/PR; do not bypass gates.

Responsive **browser acceptance remains separate**: on a real isolated V2 staging Dashboard, test 320/360/390/412/430/768/1024/1280px, keyboard navigation, drawer focus/Escape, label overflow, Arabic and English, empty/loading/error states and cross-store attempts. Functional browser proof (screenshots optional) is required before closing #2506. Owner-lock master #2503 remains open.

## Impact matrix

- **Backend:** no production code/API/schema/migration changes; only Laravel Feature regression test.
- **Dashboard:** existing navigation tree and page renderers reused, no live UI/CSS changes.
- **Customer App:** not affected (no API, Flutter, account/credit changes).
- **Driver App:** not affected (no dispatch/wallet/API/Flutter changes).
- **Van App:** not affected (no visit, routing, VAN-only B2B dispatch or Flutter changes).
- **Invariant:** B2B **VAN ONLY**, B2C **DRIVER ONLY**, effective permission and finance maker/checker rules unchanged.

No main integration, release tag, production publish, cPanel, database mutation, or branch deletion.
