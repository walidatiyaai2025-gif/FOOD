# V2 #2895 / P373 — Compact status badges for authorized Sidebar items

**Source:** FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09), P373. Parent #2503. Integration target: **Foodex_V2 ONLY**.

## Placement and security authority

- Actor: active, role-authorized Dashboard operator in a properly assigned B2B/global or B2C/store scope. Navigation type **SIDEBAR**. Placement: **Reuse → Extend** the existing authorized Sidebar item partial; no new screen, route, role, policy, API or entitlement.
- The canonical AdminNavigation::groupsFor(User) plus AdminSidebarManager authorization, store/tenant/channel, module and operational readiness filtering MUST execute before rendering. V2SidebarItemStatus only displays one code-owned classification on a surviving authorized item. It does not calculate or grant access.
- Only trusted admin_sidebar.items.<item_key>.status may opt in to one of: new (جديد), paused (موقوف), beta (تجريبي), approval_required (يتطلب اعتمادًا). Empty, unknown, unregistered and malformed statuses render no badge. No actual operational item has been assigned an invented classification by default.
- The paused label is descriptive only, **not** a permission, entitlement or disabled-route policy. If a destination is genuinely disabled or uncertified, its route and backend/module controls must deny it; never rely on this label to block access. Future classifications need an explicitly approved factual business/release source.
- Badge sits within the label of the already permitted link, one per item at maximum. Reuses FOODEX styling tokens, wraps on narrow widths, preserves current AR/EN subtitle, RTL/LTR, keyboard focus, aria-current, link search/filter, native group disclosure and route attributes. Group-level statuses P164 were separately merged as PR #3012; the group resolver and group badges are unchanged.

## Impact

| Surface | Status | Justification |
| --- | --- | --- |
| Laravel Dashboard responsive web | Affected | Existing Sidebar link label, compact localized item badge |
| Laravel route/API/RBAC/database | Not affected | No controller, route, permission, policy, data mutation, entitlement or migration change |
| Customer Flutter | Not affected | No app code, auth, endpoints, pricing, checkout or deep links changed |
| Driver Flutter | Not affected | No app code, GPS, B2C delivery, dispatch or endpoint changed |
| Van Flutter | Not affected | No app code, B2B routes, visits, settlement, dispatch or endpoint changed |

B2B **VAN ONLY**, B2C **DRIVER ONLY**, store-scoped authorization and finance maker/checker are unchanged.

## Automated acceptance and outstanding real-browser checks

V2SidebarItemStatusBadgeTest covers all four allowlisted statuses and live Dashboard HTTP in Arabic RTL and English LTR, a single label per item, unclassified and forged metadata suppression, an unchanged authorized navigation tree, B2C own-store access vs foreign-store 404 and B2B 403, inactive/roleless direct-route denial, and no route-permission expansion.

Required CI on the exact final PR head: Repository Policy, FOODEX V2 Isolation Gate and Required CI including Laravel, security, PHP syntax/Pint/PHPStan, AR/EN localization and MySQL/Redis install/upgrade/recovery where applicable. Failed or pending checks must be resolved on the same issue branch and PR; no CI bypass.

**Pending independent acceptance:** actual isolated V2 staging browser keyboard and assistive-tech announcements, link click/active state, overflow and readable badge contrast at 320/360/390/412/430/768/1024/1280px, AR RTL/EN LTR, and business approval of any live item classification. Keep issue OPEN until independently evidenced. No main integration, production change, tag or release.
