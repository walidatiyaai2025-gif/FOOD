# V2 #2875 / P164 — Business-group status badges

**Source:** Unified FOODEX RBAC/ERP/Sidebar document, 2026-11-09 P164; parent #2503. Merge target: `Foodex_V2` only.

## Exact placement and safety contract
- Existing canonical Dashboard Sidebar `AdminNavigation::groupsFor(User)` provides the authorized, store/channel/module/role-filtered navigation. `AdminSidebarManager::apply` and actual server-side route guards are unchanged.
- Actor: an active Dashboard user with authorized global B2B or assigned B2C store role. Reuse → **Extend** native business group `details/summary` inside `admin._sidebar`. Navigation type SIDEBAR; no new route, page, API, permission, tenant context, master-data field or JS.
- An approved registry metadata entry `admin_sidebar.groups.<group_key>.status` may be `new`, `approval_required`, `exceptions`, or `beta`. Only the trusted registry may opt in. Unknown group, no authorized children, absent or malformed status: **no badge**. No default group classifications are invented. Any future opt-in must have genuine approved release, approval or exception evidence; do not infer status from lifecycle ENABLED or sidebar presence.
- Small localized AR/EN visual status in the same title cell, separate from page counts and native disclosure. Native keyboard, focus and screen-reader summary remain; badge has readable localized text/aria-label. Existing RTL/LTR and mobile 320/360/390/412/430/768/1024/1280 CSS retained. No decorative status can unlock a route, grant a role, disclose another tenant or certify readiness.
- Group-only P164 intentionally does not preempt #2895 P373 item/module badge requirements.

## Per-surface impact
| Surface | Assessment | Evidence |
| --- | --- | --- |
| Backend Laravel presentation projection | Affected | New allowlisted V2SidebarGroupStatus; existing authorization, runtime, database, API, entitlement unchanged |
| Dashboard | Affected | One canonical Sidebar summary title, compact accessible AR/EN badges, unchanged counts/links |
| Customer Flutter | Not affected | No Customer code, API payload, auth, pricing, routes or deep links changed |
| Driver Flutter | Not affected | No Driver code, delivery/dispatch or related endpoint changed |
| Van Flutter | Not affected | No Van code, routes, settlements or related endpoint changed |

B2B VAN ONLY / B2C DRIVER ONLY and finance maker-checker unchanged.

## Acceptance
`V2SidebarGroupStatusBadgeTest` covers all four states across AR RTL/EN LTR, default/invalid metadata, authorized B2B and assigned B2C store, foreign-store 404, B2B cross-channel 403, inactive and unassigned actors, link inventory unchanged and existing counts. Regression `V2SidebarAuthorizedGroupCountsTest` and AdminNavigationAuthorizationAuditTest remain. Require exact-head Repository Policy, V2 Isolation and Required CI Gate (PHP/Pint/PHPStan/Composer Security, Laravel tests, MySQL Redis real install/upgrade/recovery) before merge.

**Outstanding:** no live staging browser proof yet for keyboard/focus/screen-reader or target viewports, and no business-authorized status metadata assigned to real groups. Keep issue OPEN until those are independently evidenced. No main, tags, production, release.
