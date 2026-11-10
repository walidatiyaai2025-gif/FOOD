# V2 #2872 / P161 — Authorized Sidebar quick search

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P161. Parent #2503. **Owner-locked PR base `Foodex_V2` ONLY**; no `main`, production release/tag or deployment.

## Placement and business authority

- Business domain: Dashboard navigation, all domains already authorized in the current session.
- Personas: authenticated B2B global manager; authenticated B2C store manager in owned store; Platform Owner/Super Admin diagnostic view only to the extent `AdminNavigation` grants authorized routes.
- Entry point: existing persistent Dashboard Sidebar search field, desktop expanded/compact and mobile web off-canvas drawer. Reuse → **Extend** existing `admin._sidebar` and `admin._sidebar-node`; no new screen, tab, Sidebar destination, HTTP search endpoint, permission, or side-effect.
- Canonical source of truth: `AdminNavigation::groupsFor($user)` returns server-authorized, effective role/permission, scope, channel, module/entitlement and store-specific navigation before Blade renders. The client indexes **only DOM links already emitted by this pipeline**. The search never creates a route or changes server authorization.
- Search labels: the existing `data-nav-label` contains primary and alternate Arabic/English authorized display labels; group-summary text can also match. No raw internal keys or sensitive server records enter the search index.

## Search behavior

1. Filter by typed substring within bilingual link labels and business group names (case/NFKC normalized); no network requests or per-keystroke database queries.
2. Unmatched page links, nested wrappers and empty groups are actually hidden, including under existing `display:grid` styles; matching descendants keep their parent link visible as context.
3. Expand matching groups temporarily; clearing input restores the group's state from before search. Built-in search clear also triggers `input`.
4. A translatable, screen-reader announced `role=status` displays an empty state when no authorized destination matches. The input has an explicit accessible name and search landmark. Pressing Escape clears a nonempty query; a subsequent Escape retains the existing mobile drawer close/focus behavior.
5. AR = RTL and EN = LTR use the existing shared Tajawal and logical CSS; no new hardcoded UI strings or color tokens. Sidebar search does not grant RBAC or make disabled/foreign-store/other-channel pages accessible.

## Acceptance coverage

- `backend/tests/Feature/V2SidebarSearchAuthorizationTest.php` checks AR/EN HTTP response, RTL/LTR, labeled search and empty state, authorized navigation-only rendered `href` inventory (recursively including nested links), and no leaking links from role-specific visibility or foreign-store/wholesale domains.
- Reject unassigned/inactive users and direct retail→wholesale and foreign-store route access with HTTP 403/404. Existing authorization tests for denied modules, store-scoped grants, finance SoD and module readiness remain required regression gates.
- UI runtime states to audit on isolated V2 staging: Arabic and English at 320, 360, 390, 412, 430, 768, 1024, 1280px. Enter/clear query; no matches; group-label match; nested child match; keyboard Tab/Escape; focus restoration, mobile drawer; RTL/LTR and screen-reader status.
- Canonical behavior validation on exact PR HEAD: Repository Policy, V2 Isolation, AR/EN localization, PHP syntax/Pint/PHPStan, Laravel tests, MySQL/Redis install/upgrade/recovery and `required-ci-gate` must all be success before isolated merge.
- Screenshot-specific CI is retired by `uiux-evidence.md`; behavioral tests, auth checks and the independent browser audit remain meaningful. Do not call this fully accepted if real V2 staging browser/accessibility evaluation remains unverified.

## Cross-app impact matrix

| Surface | Impact | Evidence |
| --- | --- | --- |
| Laravel Backend | No new endpoint / permission / DB migration | Existing `AdminNavigation` authorization pipeline unchanged |
| Dashboard | **Affected** | Shared sidebar search filter, existing screen-only enhancement with AR/EN search empty state |
| Customer Flutter | Not affected | No Flutter source, customer API/OpenAPI/permissions/navigation or token changed |
| Driver Flutter | Not affected | No driver route, delivery, authorization, dispatch, notification or API contract changed; B2C=Driver only |
| Van Flutter | Not affected | No van route, field operations, GPS, assignment, dispatch or API contract changed; B2B=Van only |

No new mobile issue is warranted for a Dashboard-only, local DOM filter. If API, role or mobile route contracts change in a later PR, create the corresponding separate V2 mobile issue.

## Rollback and operator controls

The change is presentation-only (Blade JS/CSS and AR/EN translation keys), without persistent writes, migrations, cron, queue, or new service. Rollback via V2 PR review/revert of the exact commit does not touch data. Search result links continue to require the server-side authorization at the destination. Keep #2872 OPEN if independent staging UX/accessibility acceptance remains to be attached.
