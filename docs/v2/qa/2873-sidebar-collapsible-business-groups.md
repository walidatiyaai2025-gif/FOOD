# V2 #2873 / P162 — Fixed, collapsible business groups (authorized Dashboard Sidebar)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P162, parent #2503. Owner-locked integration branch: `Foodex_V2` only. No `main`, tag, release or production deployment.

## Authority, placement and actor

- Surface: Laravel Dashboard, shared `admin._sidebar`; user-visible entry: existing business-group `<details><summary>` above previously authorized pages.
- **Reuse → Extend.** Native `<details>` delivers keyboard Enter/Space and assistive-technology expanded/collapsed semantics. No second Sidebar, new page/tab, database table, global API, or permission.
- Roles/tenants: B2B_ADMIN global Wholesale, B2C_STORE_ADMIN within assigned retail store, Platform Owner/Super Admin only in the channels permitted by canonical `AdminNavigation`. `AdminNavigation::groupsFor($user)` remains the sole server-authorized provider of visible groups/pages. Client-side collapsed state never adds a group or a route.
- Business group registry keys stay fixed in the authoritative navigation. A group without authorized children does not render; a disabled/denied/foreign-store destination does not become searchable or accessible through group state.

## Behavioral contract

1. The current navigation-context group is expanded on page render so the active page remains discoverable.
2. Other groups use a per-authenticated-user local preference `foodex.sidebar.groups.{user_id}` (mapping of already rendered group IDs to booleans). First visit retains the existing desktop closed / compact native behavior. Historic malformed or unavailable localStorage falls back safely to functional native disclosure; data is strictly presentational and stores no route/secret/user record.
3. A real click/keyboard activation of a native `<summary>` updates just its own open state without reordering, hiding, or changing canonical RBAC. Link clicks continue navigation.
4. Group expansion triggered programmatically by Sidebar search is transient; search clear restores the pre-search open state and does not persist the temporary expansion, preserving V2 #2872.
5. Arabic/right-to-left and English/left-to-right reuse the same labeled `summary`, Tajawal/FOODEX tokens and responsive mobile drawer. No new visible translation strings, colors, widgets or icon-only controls.
6. No data writes, backend mutation, new dependency, queue, migration, log manipulation or privileged action. The browser only saves disclosure preferences in user-scoped localStorage.

## Acceptance evidence

- Regression: `backend/tests/Feature/V2SidebarBusinessGroupCollapseTest.php` verifies AR/RTL and EN/LTR HTTP 200 for B2B_ADMIN, exactly the group keys returned by server `AdminNavigation`, native summaries, per-user storage-key isolation, and scoped retail actor's B2B 403 and foreign-store 404; roleless and inactive actors are rejected.
- Existing Sidebar authorization/visibility and V2 #2872 search regression suite must pass unchanged; disclosure is independent of permissions, channel/tenant ownership, module entitlement and operational-readiness checks.
- For independent staging browser acceptance (not claimed by this code PR): verify group toggle/keyboard/native expanded-state; remembered group open state on reload and per-user switch; search matching, clearing and empty results without leaking/overwriting preferences; 320/360/390/412/430/768/1024/1280px, AR RTL and EN LTR, mobile drawer Escape and screen-reader navigation.
- CI required before isolated merge: latest exact-head Repository Policy, V2 Isolation Gate, AR/EN localization, PHP syntax/Pint/PHPStan/Composer security/OpenAPI, full backend tests and MySQL/Redis install-upgrade-recovery `required-ci-gate`.
- Tests/CI validate Laravel output and server-side authority, not a full independent real-browser accessibility signoff. Keep Issue #2873 OPEN until actual staging viewport/keyboard/screen-reader evidence is attached; do not represent unverified UX acceptance as done.

## Backend, Dashboard, Customer, Driver, Van impact matrix

| Surface | Impact | Evidence |
| --- | --- | --- |
| Laravel Backend | No API/DB/permission change; new tests only | Existing `AdminNavigation` and route authorization unchanged |
| Dashboard Web | Affected | Shared Sidebar native disclosure remembers authorized business-group open state; no page creation |
| Customer Flutter | Not affected | No customer API/deep-link, Flutter, auth, order or financial contract change |
| Driver Flutter | Not affected | No Driver code, scoped auth/dispatch API or workflow change; B2C remains DRIVER ONLY |
| Van Flutter | Not affected | No Van code, scoped auth/dispatch API or workflow change; B2B remains VAN ONLY |

No `V2 | MOBILE` issue is justified for a client-side Dashboard disclosure preference. If later work changes an API consumed by any mobile app, create its own linked V2 issue beforehand.

## Rollback

Presentation-only Blade JavaScript change; no persistent server data or migration to reverse. A V2-only revert can restore the original native toggle behavior. Any historical browser localStorage key is inert without the corresponding code. Search and route authorization are server-controlled regardless of rollback.
