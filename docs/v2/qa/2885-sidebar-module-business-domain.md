# V2 #2885 — New ERP module placement in the correct business domain (P361)

## Source / owner boundary
Source `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P361; master #2503. **Merge to Foodex_V2 only.** No main, release or live deployment.

## Existing canonical placement — Extend, not a new screen
- Domain: ERP and business-domain Dashboard Sidebar; entity: existing authorized module/page node.
- Actor: already-authorized Dashboard role and scope (B2B global or assigned B2C store); user goal: find an enabled module in its business domain, not under a stale route-authoring group.
- Workflow/density: frequently visited compact Sidebar tree; no new form fields, routes or UI components.
- Authoritative navigation: `AdminNavigation::groupsFor(User)` with `AdminSidebarManager::apply`; group/item registry `config/admin_sidebar.php`; server route middleware and RBAC independently authorize each destination. Navigation type `SIDEBAR`.
- Placement: **Extend** the existing server projection. Newly registered module item with non-empty `module` and valid explicit `group` is placed in that registry business group by default; no automatic route/module registration is performed. A saved administrator layout grouping takes precedence, preserving current personalization/history. Unregistered group falls back to original canonical navigation group; no new group is invented.
- Desktop/tablet/mobile web, AR RTL / EN LTR: unchanged shared Dashboard Sidebar, existing translation keys, existing off-canvas/keyboard behavior, no new strings or widgets.
- Disabled existing group or item continues to hide the node; no granted permission, readiness, entitlement or access because of placement. Current direct-URL 403/404 protection is unchanged.

## Impact matrix
| Surface | Impact | Evidence |
| --- | --- | --- |
| Laravel backend navigation service | Affected | A registered module's default business-domain grouping is resolved only after canonical authorized navigation is produced |
| Dashboard | Affected | Existing Sidebar group placement, no new screen/route/JS/Blade |
| Customer Flutter | Not affected | No Customer API, auth, payload, deep link, or Flutter source changes |
| Driver Flutter | Not affected | No Driver app, dispatch or delivery API changes |
| Van Flutter | Not affected | No Van app, order assignment, route or fulfillment API changes |

B2B=VAN ONLY; B2C=DRIVER ONLY and financial maker/checker controls unchanged.

## Verification
- `V2SidebarNewModuleBusinessDomainTest`: one already authorized item with new module annotation moves to declared valid business group, shows AR/EN translated title and keeps the exact authorized link inventory; invalid group and untagged page do not move; saved layout overrides, disabling group hides without revoking base HTTP access; B2B/B2C direct HTTP and cross-store denial.
- Regressions: `V2SidebarLegacySettingsPreservationTest`, `V2SidebarNoRbacEscalationTest`, `V2SidebarMetadataTest` and `AdminNavigationAuthorizationAuditTest`.
- Required exact-head Repository Policy, FOODEX V2 Isolation Gate and Required CI (backend Pint/PHPStan/test/MySQL-Redis install/upgrade/recovery) must all be green for PR merge; skipped Flutter workflow jobs must be justified by unchanged app paths.
- Real isolated staging browser keyboard/focus/RTL/responsive visual acceptance across 320–1280 px remains **unverified** until independently evidenced; do not close Issue solely on CI/merge.
