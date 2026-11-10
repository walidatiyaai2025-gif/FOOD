# FOODEX V2 #2512 — Retail Dashboard Overview authorization and route acceptance

**Source:** \`FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx\`, Part 2 §4 / P182. Parent #2503. Integration target: **Foodex_V2 only**. No main promotion, release or live cPanel action.

## Placement & authority

- **Domain/entity:** Overview → Retail Store Dashboard, the operational B2C store home.
- **Actor/goal:** active authorized B2C Store Admin enters the existing overview for one assigned retail store and reads only the selected store. No role or label may convey B2B wholesale privileges.
- **Existing parent/route:** reuse \`AdminNavigation::groupsFor\` Overview → \`b2c_dashboard\` and existing \`admin.b2c.module\` with \`module=dashboard\` and the **explicit authorized \`store_id\`**. \`admin.b2c.dashboard\` is the canonical URL for the same B2cWorkspaceController; the Sidebar uses the module route. No new page/tab/drawer/endpoint.
- **Guard:** \`AdminNavigation::for\` and \`TenantContextResolver::retailStoreIds\` govern entry. \`B2cWorkspaceController::workspaceContext\` authorizes the exact assigned store, checks B2C channel and active store, and denies unknown/foreign stores (404). A global B2B-only role or a no-role account receives 403 even with a forged \`store_id\`. Store role grants and permissions remain server-side; personal Sidebar settings cannot grant access.
- **Modules/readiness:** existing B2C \`RETAIL_STORE\` dashboard; no ERP module enablement, entitlement change or readiness claim. Unsupported restaurant modules remain separately guarded by the existing business-profile contract.
- **Labels/localization:** existing \`admin.premium_nav.dashboard\` (Arabic \`لوحة التحكم\`, English \`Dashboard\`) under the B2C authorized context; direction RTL/LTR from user locale. Parent navigation group retains business context; no unsafe global label rename.
- **Fallback/states:** authorized store selection stays within the existing B2C Dashboard; empty data is handled by real \`B2cDashboardService\` (no mock content shipped). Unauthorized direct route gives 403; foreign/inactive store 404. No schema or user data changes.

## Acceptance evidence

\`backend/tests/Feature/V2RetailOverviewDashboardAuthorityTest.php\` covers both AR and EN, rendered RTL/LTR and canonical \`b2c_dashboard\` key/route/params, assigned-store 200 and \`navContext\`, foreign and inactive stores 404, Retail → Wholesale direct URL 403, and global B2B-only/no-role negative access to Retail.

Required before isolated merge: latest exact PR head Repository Policy; FOODEX V2 Isolation; full Laravel backend suite; PHP lint/Pint/PHPStan/Composer security; AR/EN localization; MySQL/Redis Fresh Install/Upgrade/Recovery; and Required CI final green. Correct failures in same branch and PR.

Independent *real browser* acceptance on isolated V2 Dashboard remains outstanding: 320/360/390/412/430/768/1024/1280px, keyboard focus/Escape, off-canvas behavior, AR/EN, long store names, empty/error/loading states and role/store negative URLs. Functional browser evidence, not screenshots, is required before closing #2512. Keep master #2503 open.

## Backend/Dashboard/Customer/Driver/Van impact

- **Backend:** new tests only. No API, controller, SQL migration, seed or runtime route changed.
- **Dashboard:** existing responsive Blade Sidebar and Dashboard, no CSS/JS/HTML changes. Real browser acceptance must still verify layouts.
- **Customer App:** **not affected**, no customer API/Flutter payload or B2B credit changes.
- **Driver App:** **not affected**, no driver API, assignment, wallet, navigation or Flutter changes.
- **Van App:** **not affected**, no Van routing/visit/dispatch/Flutter or B2B channel changes.
- **Invariants:** B2B **VAN ONLY**, B2C **DRIVER ONLY**, tenant/store-scoped RBAC and finance maker/checker remain intact.

No main merge, production deploy, tags, release artifact, version promotion or active branch deletion.
