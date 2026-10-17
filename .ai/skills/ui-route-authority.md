# Skill: FOODEX UI Route & Journey Authority

Use for every new page/screen, route, navigation entry, deep link, tab destination or replacement renderer.

## 1. Canonical source

Always inspect:
- `docs/execution/UI_ROUTE_AUTHORITY.json`
- the current router/navigation source for the surface
- existing navigation/authority tests

Do not infer canonical ownership from a screenshot or old route name.

## 2. Surface authority

### Customer
Authority:
- `apps/customer_app/lib/core/routing/customer_route_authority.dart`
- `apps/customer_app/lib/core/routing/customer_router.dart`

Tests:
- `apps/customer_app/test/customer_route_authority_test.dart`
- `apps/customer_app/test/customer_navigation_test.dart`

A Customer feature must not create a second renderer for a function already assigned to a canonical authority.

### Driver
Authority:
- `apps/driver_app/lib/navigation.dart`

Tests:
- `apps/driver_app/test/navigation_test.dart`

Driver B2C/B2B channel partitions are security/product boundaries. A route must not bypass them.

### Van
Authority:
- `apps/van_app/lib/features/foundation/van_screen_inventory.dart`
- `apps/van_app/lib/features/foundation/van_foundation_screen.dart`

Test:
- `apps/van_app/test/app_test.dart`

The frozen production inventory is the navigation authority unless an explicit product change updates it.

### Dashboard
Authority:
- `backend/routes/web.php`
- current Admin navigation service/source

Test:
- `backend/tests/Feature/AdminNavigationAuthorizationAuditTest.php`

A visible navigation destination must open for the intended actor and remain unavailable to actors without permission.

## 3. Route Authority Lock

Before UI implementation state internally and carry the resolved values into the Issue/PR evidence:

```text
Domain:
Role:
Capability / function:
Surface:
Canonical route:
Canonical renderer/controller:
Navigation owner:
Navigation type:
Entry point:
Parent surface:
Actor / permission:
Active context:
Mobile discoverability:
Desktop discoverability:
RTL / LTR behavior:
Fallback / back path:
Existing authority entry:
Existing authority/navigation tests:
Deep-link behavior:
```

Allowed navigation types are `SIDEBAR`, `DOMAIN_HUB`, `PAGE_TAB`, `CONTEXT_ACTION`, `ADMIN_SETTINGS`, `WORKFLOW_STEP`, and `EXPLICIT_EXCEPTION`.

`DIRECT_URL_ONLY` does not satisfy completion for a normal business capability. `EXPLICIT_EXCEPTION` is only for intentionally non-browsable technical routes/surfaces and must document why no visible product entry point is correct.

If the function already has an authority, modify that implementation rather than create a sibling replacement. Do not solve discoverability by automatically adding a Sidebar item; use the smallest correct navigation owner under the adaptive placement contract.

## 4. New canonical route/screen rule

A genuinely new canonical function requires, in the same task:
- one route/renderer authority;
- normal navigation entry if users need routine access;
- permission/channel/store scope;
- route authority registry update when applicable;
- route/navigation regression test;
- visual evidence inventory/capture update when UI acceptance applies;
- removal/redirection of obsolete duplicate authority when replacing an old canonical screen.

Do not leave two "temporary" production screens for the same business function.

## 5. Deep links / push

A deep link:
- identifies an intended exact record/function;
- revalidates the authenticated actor against current backend scope;
- does not grant access because the payload knows an ID;
- handles stale/revoked assignment/order links safely;
- lands on the canonical renderer, not a duplicate shortcut screen.

## 6. Tabs are not duplicate routes by default

Page tabs may represent sibling functions inside one canonical domain workspace. They still require:
- one clear owning domain route/workspace;
- direct link/bookmark semantics when the product requires them;
- no hidden second data/business implementation per tab.

## 7. Completion gate

PASS only if:
- no duplicate route/screen authority was introduced;
- canonical source is documented/updated;
- the Issue/PR records the full discoverability metadata required by `UI_PLACEMENT_SCREEN_PATTERN_CONTRACT.md`;
- an authorized user can reach the capability from an existing visible product surface without relying on an undocumented direct URL;
- the PR proves start → click → landing → active-context/back-path behavior;
- the selected navigation level is role-correct and does not inflate the Sidebar;
- Arabic/English and mobile/desktop navigation remain discoverable;
- permissions/channel scope are enforced server-side;
- authority/navigation tests pass;
- evidence harness knows the canonical surface where required.
