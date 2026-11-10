# V2 #2754 / P009 — Effective permission and scope contract

- **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P009; parent #2503.
- **Branch:** `test/2754-v2-effective-permission-scope-contract`; **integration target:** `Foodex_V2` only.
- **Canonical behavior:** `User::hasPermission()`, `User::inheritedPermissionCodes()`, `User::effectivePermissionCodes()` and persisted `user_permission_overrides`. Reuse the current Security Center grant/revoke service and existing server authorization rather than inventing a second permission registry.

## Executable evidence

`backend/tests/Feature/V2EffectivePermissionScopeContractTest.php` tests:

1. Active B2C store-role inheritance is confined to an assigned active Retail store; an unrelated store or global context does not inherit that grant. An active Wholesale global role remains effective for B2B but cannot promote a B2B-only account permission inside Retail.
2. An individual store ALLOW adds only the intended action on the assigned store; a store DENY removes an inherited action only within that store; a global DENY defeats a conflicting store ALLOW. `hasPermission` and the effective permission-code projection must agree, and the shared role template remains untouched.
3. Forged ALLOW rows targeting an unassigned store, Wholesale store or B2B-only action in Retail fail closed. Direct protected Dashboard HTTP access returns 403 in both Arabic and English locales.

The tests seed real built-in roles and Stores and use actual persisted permissions/overrides, not mocked authorization. The existing P091 #2828 integration tests additionally cover Security Center mutation, audit event, operational tenant scope and positive authorized HTTP access; this issue's regression supplements rather than replaces those tests.

## Scope and safety

- **Backend/API:** Existing canonical permission logic is the authority; test-only change, no API shape, route, permission grant, migration or service change.
- **Dashboard:** No Blade/navigation change. Tests exercise real protected Dashboard routes in AR/EN; RTL/LTR visual acceptance remains on the final V2 UX gate #2750.
- **Customer:** Runtime/API endpoints unchanged, no new app scope. Existing customer authorization contract retained; no Flutter file edited.
- **Driver:** Runtime/API endpoints and B2C=DRIVER fulfillment unchanged; no Flutter file edited.
- **Van:** Runtime/API endpoints and B2B=VAN fulfillment unchanged; no Flutter file edited.
- **Tenant/finance:** No bypass of cross-store or cross-channel boundaries, independent finance SoD, module readiness, route authorization or immutable audit history. This patch does not itself change their behavior.

## Verification and closure

Merge **only** to `Foodex_V2` after the latest PR head passes required Repository Policy, V2 Isolation, PHP syntax/Pint/PHPStan/security/OpenAPI, localization, Laravel tests, MySQL/Redis deployment acceptance and `required-ci-gate` (as applicable). Record exact head, PR, merge SHA and checks before marking implementation complete. Manual owner/browser/device acceptance: **DEFERRED_TO_V2_FINAL**, not a production readiness claim. Never merge to `main`, tag, release or deploy.
