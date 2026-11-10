# FOODEX V2 #2507 — Overview Action Center authoritative route acceptance

Source: \`FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx\`, Part 2 §4 P177; parent #2503. **Merge target: Foodex_V2 only**. No main, tags or production deployment.

## Navigation and placement

- **Domain/entity:** Overview / approval work queue and My Work actions; current canonical route \`admin.action-center.index\` (GET \`/admin/action-center\`).
- **Actor/user goal:** authorized global B2B administrator or assigned-store B2C Store Admin reviews and acts on their permitted pending work. Inactive users, Customer Service Agent and retail users with no store assignment do not gain this overview entry.
- **Pattern:** REUSE existing Action Center and authorized \`AdminNavigation::groupsFor\` Overview group. No new page, menu key or Sidebar-visible permission policy. The group is projection-only; \`EnterpriseActionCenterController\` independently enforces \`canAccessMyWork\` on index, export and decide.
- **Known entry point:** Overview → \`enterprise_action_center\` with \`enterprise_action_center.nav_title\`; page \`navContext=enterprise_action_center\`. Back/fallback: existing Overview and role-appropriate dashboard; no new navigation route.
- **Channel / scope:** B2B global role is accepted; assigned B2C Store Admin is accepted with current tenant's store, and cannot use a foreign-store module route. Customer Service Agent uses its separately authorized service workspace, never the platform Action Center through this entry.
- **Permissions:** \`AdminNavigation::canAccessMyWork\` requires an active user and suitable global role or active store role with store scope. \`admin.action-center.index\`, \`admin.action-center.export\`, and \`admin.action-center.decide\` enforce it on the server, before exposing any record or mutation. No Sidebar visibility override can create an HTTP privilege.
- **Modules/entitlements/readiness:** this P177 entry is an aggregate already-authorized My Work surface; individual workflow actions and drill-downs remain subject to their original module, tenant and workflow rules. This PR cannot claim an unavailable module ready.
- **Localization:** Arabic \`مركز الإجراءات\` RTL and English \`Action Center\` LTR derive from existing translation keys, with real rendered HTML regression checks.
- **States/failures:** empty pending work renders the existing page; denied actors receive 403 on index/export/decide; no data or database state is modified by this regression-only addition.

## Verification

\`backend/tests/Feature/V2OverviewActionCenterAuthorityTest.php\` tests real B2B role HTTP and Overview navigation, AR/EN RTL/LTR, B2C assigned-store access versus a foreign store 404, inactive/global and Customer Service Agent deny, unassigned retail account deny, and 403 for direct export/decision requests with no permission. Existing Action Center, Overview and route/security tests remain in place.

Dashboard desktop/mobile-web reuse the same Blade/nav tree; this change introduces no CSS/JS/HTML. Independent real-browser acceptance remains required for 320/360/390/412/430/768/1024/1280px, focus and Escape behavior, AR/EN overflow and zero-work states; **do not close Issue #2507 before this evidence exists**.

Backend business API: **not affected**, no controller/route/policy/migrations changes. Customer App: **not affected**, no customer API or Flutter changes. Driver App: **not affected**, no Driver dispatch/API/Flutter changes. Van App: **not affected**, no VAN visit/fulfillment/API/Flutter changes. B2B VAN-only / B2C DRIVER-only stays intact. DB data and preferences are preserved.

Required proof: exact-head Repository Policy, V2 Isolation Gate, AR/EN, PHP lint/Pint/PHPStan/Composer audit, full Laravel suite, MySQL Redis fresh install/upgrade/recovery. Code merged only through PR to Foodex_V2. No release/build claim without actual artifact verification.
