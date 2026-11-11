# V2 #2665 / P335 — Administration Roles destination and HTTP authority

## Source, actor, placement
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P335 (**الأدوار**, Administration), parent #2503. Target integration `Foodex_V2` exclusively.
- **Reuse** existing Administration Hub `admin.administration.index` → Security & Users card → existing Security Center `admin.security.index?section=roles` tab. The role catalog/search, localized role labels and allowed mutations live here. No duplicate sidebar destination, dead placeholder, direct-URL-only capability, or second role service.
- Personas: SUPER_ADMIN has read and role governance according to existing gates. An explicitly granted global `security.view` actor has read-only access. A roleless actor has no role-catalog access; a scoped Retail store role without global security authority also cannot enter the platform role catalog. `roles.manage` is a separate authorization for writes.
- Authority: GET `SecurityController::index` enforces Laravel `Gate::authorize('security.view')`; POST `security.roles.store` and PATCH `security.roles.update` enforce `roles.manage`. Source P335 is an existing core Administration feature, not an optional ERP module. A disabled ERP module cannot itself grant any role-management permission.

## Functional/negative evidence
`backend/tests/Feature/V2AdminRolesNavigationAuthorityTest.php` checks real HTTP and DB state, not a cosmetic link alone:
1. A SUPER_ADMIN reaches the Security card on the actual Administration Hub and opens the canonical Roles tab, with populated searchable role cards, accessible current-tab indicator and AR RTL / EN LTR document directions.
2. A synthetic active global role with `security.view` but no `roles.manage` sees the read-only tab but cannot see the create-role form or perform role create/update HTTP; no role data is changed.
3. A roleless authenticated actor receives HTTP 403 on the exact Roles tab and on a guessed role mutation URL. Existing role grants are not modified.
4. A store-only Retail actor, scoped by an actual synthetic B2C store assignment but without global `security.view`, cannot use that assignment to open the platform Roles catalog or create a global role (HTTP 403, no created role).
5. Display-only GET requests preserve role counts, permission pivots and audit log totals. No production user, account or store records are accessed.

## Placement and UI contract
- `domain=Administration`, `entity=Role`, `user_goal=discover, inspect or administer authorized roles`, `placement_type=PAGE_TAB`, `parent_surface=Security & Users`, `navigation_owner=Administration Hub`, `entry_point=Administration Hub security card`, `canonical_route=admin.security.index?section=roles`, `fallback=Security Center users tab`.
- Route discoverability uses the existing card and tab, not a new Sidebar key. Role cards use the canonical Security Center responsive implementation for desktop/tablet/mobile web. Existing FOODEX design/a11y and localization contracts continue to apply; this PR changes no UI markup or translation keys. Runtime screenshots or manual UAT are not claimed.
- Tests cover the backend-authoritative allow/deny and AR/EN text and document direction. The existing Dashboard mobile web and accessibility suites remain required; no invented visual proof.

## Cross-surface impacts
| Surface | Classification | Reason |
| --- | --- | --- |
| Backend/Dashboard | **Tests/evidence only** | Existing `security.view` and `roles.manage` routes/roles are preserved. No app behavior or data migration. |
| Customer Flutter | **Not affected** | No API, auth, customer identity, order or Flutter file/contract changed. |
| Driver Flutter | **Not affected** | No B2C dispatch, driver identity, route, payload, notification or Flutter change. |
| Van Flutter | **Not affected** | No B2B Van routing/visits/identity, API payload or Flutter change. |

## Release/acceptance
One branch `test/2665-v2-roles-admin-hub-authority`, one PR to **Foodex_V2** only. Test `php artisan test --filter=V2AdminRolesNavigationAuthorityTest`; require exact-head Repository Policy, FOODEX V2 Isolation Gate, Laravel full suite, AR/EN, security lint/static analysis and MySQL/Redis fresh-install/upgrade/recovery CI before merge. Preserve active workers' shared files: `AdminNavigation.php`, Security Blade/lang and ERP readiness. Manual staging/UAT is deferred under #2503. No `main`, production, release/tag, APK or cPanel mutation allowed.
