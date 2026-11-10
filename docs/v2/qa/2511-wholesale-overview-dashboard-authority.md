# FOODEX V2 #2511 — Wholesale Dashboard Overview authorization contract

**Source:** \`FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx\`, Part 2 §4 / P181; parent #2503. Integration **Foodex_V2 only**.

## Placement and exact route authority

- **Domain/entity:** Overview → Wholesale management dashboard; cross-module B2B operational summary, not a second ERP or Retail page.
- **Actor / goal:** active B2B Admin finds and opens the original wholesale dashboard using the Sidebar; a Store Admin with B2C-only assignment must never inherit wholesale authority by virtue of a visible label.
- **Existing parent / placement:** Reuse canonical \`AdminNavigation::groupsFor\` Overview group and its existing \`b2b_dashboard\` item. Existing \`admin.b2b.module\` with \`module=dashboard\` resolves the same controller as \`admin.b2b.dashboard\`; no new navigation, route, screen, form, widget or permission code.
- **Authorization:** \`AdminNavigation::for\` and B2B \`TenantContextResolver::wholesale\` are authoritative for B2B channel entry. The dashboard module has no separate privilege code, but **does require an allowed B2B actor and scope**. Sidebar cannot grant direct URL access; retail-only and inactive actors receive HTTP 403. Retail-specific URLs require exact authorized store and return 404 for foreign stores.
- **Module/entitlement/readiness:** existing B2B Dashboard module and data service; this PR adds no readiness bypass or ERP module enablement. Any separate underlying business widget retains its original permission and data scope.
- **Labels and layout:** existing \`admin.channels.b2b\` resolves Arabic \`إدارة الجملة\` RTL and English \`B2B Wholesale\` LTR. No extra translation key or duplicated sidebar item. The same authorized navigation tree serves desktop and off-canvas Mobile Web. Fallback: original channel dashboard / Overview parent; direct denied URLs fail closed.
- **States:** empty data uses the live B2B Dashboard service, not a fabricated placeholder. Active permitted, inactive/none forbidden; foreign B2C store forbidden by 404. No mutation; role and store seed records live only in isolated Laravel tests.

## Exact test evidence and acceptance gate

\`backend/tests/Feature/V2WholesaleOverviewDashboardAuthorityTest.php\` tests AR/EN: real HTML direction, effective translated label, exact \`b2b_dashboard\` route/params, B2B Admin HTTP 200 and \`navContext\`. Retail Store Admin can open assigned Retail dashboard, never B2B, and cannot open another Retail store. Inactive global B2B and no-role users cannot see/open B2B dashboard.

Run \`cd backend && php artisan test --filter=V2WholesaleOverviewDashboardAuthorityTest\` plus Repository Policy, FOODEX V2 Isolation, backend PHP lint/Pint/PHPStan, localization AR/EN, Laravel full suite, MySQL/Redis Fresh Install/Upgrade/Recovery, required-ci-gate **on exact PR head**. Regressions must be corrected on this same branch and PR; merge only to Foodex_V2 when green.

**Responsive acceptance not yet performed:** independent isolated-V2 browser verification must cover 320/360/390/412/430/768/1024/1280px, keyboard focus/Escape/drawer and RTL/LTR with actual no-data, error and loaded dashboards. Screenshots are optional, but genuine functional evidence is mandatory before closing #2511.

## App impact (Customer / Driver / Van separately)

- Backend: **test-only**, no API, endpoint, permission or schema changes.
- Dashboard: test-only existing Sidebar and Dashboard routes; mobile-web follows the identical server-authorized tree, no UI code changes.
- Customer App: **not affected**; no customer API payload/Flutter/auth/credit changes.
- Driver App: **not affected**; no driver API, route assignment, wallet or delivery changes.
- Van App: **not affected**; no B2B visit/fulfillment, VAN dispatch, routing, offline or Flutter changes.
- Invariants remain B2B **VAN ONLY**, B2C **DRIVER ONLY**, no cross-store data exposure.

No \`main\` branch modification, production release, tagging, cPanel deployment or live database change. The master #2503 remains OPEN.
