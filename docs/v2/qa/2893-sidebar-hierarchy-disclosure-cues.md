# V2 #2893 — Sidebar business hierarchy and disclosure cues (P371)

## Placement and authority
- Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P371; master #2503.
- Domain/entity: Dashboard navigation / authorized business-group tree.
- Roles: all authorized Dashboard actors; user goal: distinguish business group, page and child page and identify an expandable group.
- Workflow/frequency: routine navigation on every Dashboard visit; density: compact Sidebar.
- Existing parent / navigation owner: `admin._sidebar`, `admin._sidebar-node`, and canonical `AdminNavigation::groupsFor()`.
- Placement: **Extend** the existing Sidebar summary rather than compose/create a route or duplicate component; navigation type `SIDEBAR`.
- Canonical route/entry: existing authorized Dashboard destinations; same back path and active context.
- Desktop: visible compact localized expand/collapse text plus native chevron; collapsed desktop: icon-only layout remains.
- Mobile/tablet: responsive existing off-canvas shell; <= 440px retains chevron and native disclosure state without extra text to prevent overflow. Same structure in RTL/LTR.
- Permissions/states: no new grants, data, routes, actions, group state writes or metadata; all nodes remain filtered by AdminNavigation, server-side middleware, tenant and entitlement guards. Only native open/closed state of existing `details` is communicated.

## Implementation
- Keep native `<details>/<summary>` and the #2873 saved-group behavior. No new JavaScript or nested interactive buttons.
- Add compact, localized `Expand/Collapse` cues alongside the existing chevron, shown conditionally via CSS according to the native `open` state; decorative cue is hidden from screen readers because the native summary already exposes expanded/collapsed state.
- Keep a visible `:focus-visible` outline, >= 44px summary hit target, logical inline borders for group/page/child differentiation, and reduced text density at compact widths. Do not add untrusted status counts or override authorization.

## Security and app impact
| Surface | Classification | Reason |
| --- | --- | --- |
| Laravel Dashboard | Affected | Existing Blade group header styling/localized disclosure affordance only; route guard and rendered authorized group inventory remain canonical |
| Backend API | Not affected | No OpenAPI/route/permission/tenant/store or payload modification |
| Customer Flutter | Not affected | No customer app source, contracts or deep links changed |
| Driver Flutter | Not affected | No driver app source, contracts, dispatch or state changed |
| Van Flutter | Not affected | No van app source, contracts, assignment or delivery state changed |

Business invariant B2B = Van only; B2C = Driver only is unaffected. No finance, audit or write path is introduced.

## Acceptance
- `backend/tests/Feature/V2SidebarHierarchyDisclosureCuesTest.php`: AR/EN and direction parity, authorized group count/cue parity, native group control and focus, retail/wholesale direct-route denial, foreign-store denial, inactive/roleless denial.
- Existing `V2SidebarBusinessGroupCollapseTest` and `AdminNavigationAuthorizationAuditTest` remain regression authorities.
- Run exact-head Required CI Gate, Repository Policy and FOODEX V2 Isolation Gate on PR targeted **only** at `Foodex_V2`. Verify PHP Pint/PHPStan and MySQL/Redis install/upgrade/recovery child checks when reported.
- Independent real-browser 320/360/390/412/430/768/1024/1280px AR RTL + EN LTR click/keyboard/focus/screen-reader acceptance is **not asserted by code tests**; record remaining evidence before closing this Issue under #2503.
- No `main` integration, production deployment, tag, release or live data change.
