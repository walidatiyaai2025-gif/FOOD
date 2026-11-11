# V2 #2753 / P008 — Hidden Sidebar links and independent HTTP 403

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` paragraph P008; parent #2503. **Only target:** `Foodex_V2`; no main promotion/release/deploy.

## Canonical route policy

- The Dashboard Sidebar's `AdminNavigation::groupsFor(User)` and `AdminSidebarManager::apply(User, groups)` are presentation projections of server-authorized destinations, not independent grants.
- Security Center/Sidebar Role Access routes enforce their own permission checks. A B2B admin without security/role permission must neither see role configuration links nor open those routes by typing a URL.
- An assigned Retail Operations user initially has a valid orders link and a working B2C HTTP route. An explicit scoped `orders.view` DENY removes the link and the **route still returns 403** regardless of visual configuration. A protected B2B route remains forbidden to the Retail actor. The role template remains unchanged.
- A Super Admin's authorized role-editor link and HTTP route remain available, guarding against overly broad hiding.
- Both denial paths are checked in `ar` and `en`; RTL/LTR visual layout, keyboard and responsive device acceptance are intentionally deferred to final V2 gate #2750 rather than misrepresented as tested.

## Executable evidence

`backend/tests/Feature/V2HiddenLinkDirectAuthorizationTest.php` uses the actual seeded roles, two HTTP controllers, a saved `user_permission_overrides` DENY, real `AdminNavigation` and server route responses. There are no fabricated gates, routes or mocked permissions.

## Impact and isolation matrix

| Surface | Impact |
| --- | --- |
| Backend | Test-only: authoritative permission decision, tenant scope and route 403 remain unchanged |
| Dashboard | Test-only: existing canonical link and protected HTTP route, no Blade/route/layout mutation |
| Customer | Not affected; no Customer API contract, account state, Flutter screen or checkout change |
| Driver | Not affected; no auth/dispatch/notification/fulfillment contract changed; B2C=Driver preserved |
| Van | Not affected; no auth/van route/visit/fulfillment contract changed; B2B=Van preserved |

No new rights, cross-store data, finance permission, write operation, schema migration or live-side effects. The parent security #2748 / UX #2750 gates own broader E2E, SoD, tenant/module readiness and physical-browser acceptance.

## CI and closure contract

Exact final PR head must pass Repository Policy, V2 Isolation, AR/EN, syntax, Pint, PHPStan, Composer audit, OpenAPI, Laravel and MySQL/Redis acceptance, then required-ci-gate where applicable. Merge only to `Foodex_V2`, then close only if P008 implementation is complete with SHA/CI evidence, recording `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per owner #2503. No `main`, tags, publication or production.
