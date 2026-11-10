# V2 #2891 / P369 — Quiet group icon, name, and authorized-page counter

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P369; parent #2503. Exclusively merge via PR to `Foodex_V2`, never `main`. No release, tag or production deployment.

## Placement / authority decision

- **Reuse → Extend** `backend/resources/views/admin/_sidebar.blade.php` and `admin._sidebar-node` in the established Dashboard business-domain navigation. No new page, drawer, entry, route or role-permission configuration.
- Server authority: `App\Support\AdminNavigation::groupsFor($user)` and `AdminSidebarManager::apply` already filter groups/pages using effective RBAC, tenant/store, channel, module entitlements and operational readiness. A counter counts **only links in that final, authorized list**, recursively for nested pages. It never counts hidden registry entries, reports private data or exposes a new endpoint.
- Actor: authenticated B2B global admin and B2C store-scoped manager; other privileged platform roles see only their own effective authorized groups. No cross-channel/foreign-store navigation can be inferred from the counts.
- The existing group icon and business label remain canonical. A minimal neutral counter is rendered only if the group has more than one authorized page, with a translated AR/EN accessible label; none for one-page groups, and nothing for groups with no authorized links. It is a presentational quantity, **not** a notification count, business-record count, readiness certificate or permission token.

## UX, responsiveness, accessibility and localization

- `<details><summary>` native keyboard and disclosure semantics remain unchanged; no clickable wrapper is added. Count is informative only, not a button/link.
- Sidebar summary uses a logical 4-column responsive grid for existing icon, name, optional count and chevron; chevron remains aligned when badge is absent. Compact desktop intentionally hides count, mobile web drawer restores it, preserving the 44px interactive summary and available name width.
- AR/RTL and EN/LTR labels: `sidebar_admin.authorized_pages_count` with `:count` parameter. `aria-label` describes the page quantity for a screen reader; digits use tabular alignment. No standalone raw UI language was introduced.
- No modification to search filter, previous expanded state, localStorage, mobile drawer lifecycle/focus restoration, product forms or financial/dispatch workflows. Search filters list nodes locally and does **not** change the underlying count of authorized pages.
- Independent browser audit still required at 320/360/390/412/430/768/1024/1280px for compact/mobile, AR/EN, RTL/LTR, icon/counter alignment, summary focus/keyboard, group expansion and screen-reader text. This is not claimed complete from PHPUnit alone.

## Security and functional acceptance

- New `backend/tests/Feature/V2SidebarAuthorizedGroupCountsTest.php`: bilingual HTTP 200 on real B2B dashboard, render counts exactly equal recursively authorized groups; role-specific hidden catalog page changes only presentation count, without modifying `catalog.view` permission; B2C own-store HTTP 200 versus wholesale HTTP 403 and foreign store HTTP 404; inactive and unassigned users get no navigation/403.
- Existing `V2SidebarBusinessGroupCollapseTest`, `V2SidebarSearchAuthorizationTest`, `AdminSidebarManagementTest` and direct-route RBAC/module-readiness tests remain required regression gates.
- GitHub exact HEAD must pass Repository Policy, FOODEX V2 Isolation Gate, PHP syntax/Pint/PHPStan, Composer security and OpenAPI, AR/EN localization, full Laravel tests, MySQL/Redis fresh install + upgrade/recovery and final `required-ci-gate` before any Foodex_V2 merge. Record real checks/CI IDs on the issue. No imaginary test success.
- Zero database writes, migrations, changed API contracts, extra auth pathways, financial side effects, queue jobs, notifications or uploads; rollback is code-only in the isolated branch.

## Per-surface impact matrix

| Surface | Classification and proof |
| --- | --- |
| Laravel Backend | No endpoint, role, permission, schema or data changes; one test file plus two translation labels |
| Dashboard | Affected: group-only summary counter computed server-side from final authorized links, with locale and accessible naming |
| Customer Flutter | **Not affected**: no Flutter, customer API, mobile route, token, catalog/order contract change |
| Driver Flutter | **Not affected**: no driver app/API/dispatch change; B2C=DRIVER ONLY invariant unchanged |
| Van Flutter | **Not affected**: no Van app/API/field-route change; B2B=VAN ONLY invariant unchanged |

No `V2 | MOBILE` issue is warranted from a Dashboard-only display calculation. If any later PR changes an app-facing API/auth contract, open linked app-specific V2 issues before that change.

## Handoff and closure

After code-only integration, **keep #2891 OPEN** until independent V2-staging AR/EN responsive browser, keyboard and accessibility acceptance is evidenced. Existing owner approval lock on promotion to `main` and production is unchanged.
