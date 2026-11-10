# V2 #2797 — Sidebar empty groups and stale route integrity

## Canonical route and placement
- Reuse the existing `AdminNavigation` -> `AdminSidebarManager::apply` -> Dashboard shared Sidebar; no additional route, renderer, group, or translated label.
- Domain: Retail/Wholesale management Sidebar; actor: authorized global and store-scoped roles; capability: only show live authorized links.
- The canonical Sidebar manager removes entries when `Route::has(route-name)` is false, before applying configurable layout/role visibility, including Platform Owner's valid diagnostic inventory.
- After removing dead, hidden, disabled or permission-ineligible items, no group with zero remaining children is emitted.
- This is UX/navigation hardening only; neither the Sidebar nor a hidden state authorizes HTTP actions. Existing Laravel permission and tenant middleware remain the final authority.

## Acceptance
| Case | Expected |
| --- | --- |
| All Catalog entries hidden by role visibility | Catalog group absent; other authorized groups remain |
| Role has Catalog RBAC permission but Sidebar hides its links | RBAC unchanged; direct server authorization remains canonical |
| Sidebar receives stale/unregistered route | Item removed before layout; valid authorized sibling retained |
| Last item in a group uses stale route | Entire empty group removed |
| User without roles opens Sidebar layout URL | HTTP 403 |
| Arabic RTL / English LTR | Same entries and empty-group behavior, no raw UI copy or alternate renderer |
| Desktop / tablet / mobile web | Canonical responsive Sidebar/drawer unchanged, same server-derived filtered tree |

## Cross-surface impact
- Customer Flutter: no API contract, authentication, or deep-link change.
- Driver Flutter: no API, tracking or delivery change.
- Van Flutter: no API, assignment, dispatch or visit change.
- Dashboard: existing Sidebar manager only; no new CSS, Blade or accessibility control.

## Validation and rollback
- Feature tests: `php artisan test --filter=V2SidebarDeadRouteIntegrityTest`; existing `AdminSidebarManagementTest`, `AdminNavigationAuthorizationAuditTest`.
- `vendor/bin/phpstan analyse --memory-limit=1G`, PHP lint, localization, repository policy and V2 Isolation via exact-head Required CI.
- No migration or live data change. Rollback by reverting isolated changes if needed, with no data recovery step.
- Merge only into Foodex_V2 after Required CI passes. Never main, release or production.