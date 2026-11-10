# V2 #2803 / P052 — Non-administrative account identity boundary

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P052; parent #2503. **Integration exclusively:** `Foodex_V2`.

## Canonical authority

A `users` account is an authentication identity, not a Dashboard administrative grant. Wholesale/retail customer classification is represented by the existing `customers.type` (`b2b` / `b2c`) and can exist with no `role_user` or `user_store_roles` grant. Field-operation `B2C_DRIVER`, `B2B_DRIVER` and `VAN_OPERATOR` remain execution-specific roles, not administrative access. Actual Dashboard access derives from effective backend permissions, channel/store scope, enabled role/module and the protected HTTP route. Neither the label, profile type, account creation, Sidebar presentation nor a forged global `allow` row can independently authorize `users.view`, `roles.manage` or finance administration.

**Reuse → Extend:** no new account-type enum, parallel authorizer, role, screen, route, migration or public API. The existing `User`, `Customer`, `Role` and `AdminNavigation` contracts are tested. All role and account changes remain controlled by existing RBAC and identity lifecycle flows. P052 does not silently resolve the distinct seven-category deletion dependency in #2813, custom role governance in #2833, or role archetypes in #2856.

## Focused executable acceptance

`backend/tests/Feature/V2NonAdminAccountBoundaryTest.php` seeds the existing production-authoritative core reference schema and verifies:
- Both B2B and B2C customer records linked to roleless users remain unable to access Security or Wholesale Inventory by real HTTP; no server-side Sidebar work-context is minted.
- B2C Driver, B2B Driver and Van operator retain their canonical execution roles but gain no Dashboard roles or privileged route; the original assignment persists.
- Forged global `allow` overrides of `users.view` and `roles.manage` on a roleless customer cannot produce effective permissions or privileged HTTP access.
- Both Arabic and English sessions exercise the same authorization decision. No new copy or UI rendering was introduced, so the AR/EN translation registry remains unchanged. Denied requests must be 403, not UI-only hidden links.

## Customer / Driver / Van impact assessment

- **Customer:** existing B2B/B2C `Customer` identities and mobile API/auth payloads unchanged. Customer accounts are tested as non-admin even if `is_platform_customer` is set.
- **Driver:** B2C/B2B Driver operational role membership preserved; no login, order dispatch, delivery, tracking or endpoint changes. B2C fulfillment remains Driver-only.
- **Van:** `VAN_OPERATOR` membership preserved; no Van application/API, routing, assignment or order change. B2B fulfillment remains Van-only.
- **Dashboard/Backend:** test-only new PHP file; no runtime source, UI/HTML/CSS, schema, data migration, role grant, permissions registry or audit-write behavior changed. Existing code retains audit and finance maker/checker safeguards.

## Acceptance boundary and handoff

Green feature/full Backend, Pint/PHPStan, localization, MySQL/Redis deployment acceptance, Repository Policy and V2 Isolation plus exact-head Required CI are mandatory before a single scoped PR merges to `Foodex_V2`. Do not infer E2E browser/keyboard/RTL responsive proof from code tests; independent isolated V2 staging must still verify authenticated non-admin sign-in, actual deny views, cross-channel navigation and accessibility before final closure. Leave #2803 OPEN until all owner-defined acceptance evidence is linked. No branch, PR, tag, release, production/cPanel deployment or promotion to `main`.
