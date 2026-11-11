# V2 #2908 — historical Retail Driver role authorization boundary

## Source and privacy constraints
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, **native Table 5, data row 3**; coordinator #2503; only authorized integration target `Foodex_V2`.
- The archived row records a single globally scoped `B2C_DRIVER` role and one distinct inherited permission. This is historical evidence, **not** verified current identity, role assignment, Store/Driver mapping or entitlement status.
- The Laravel test creates a wholly synthetic user in an isolated test database. Its fixture name and `example.test` address are invented and do **not** reproduce the source user's name, address, credentials, production identifier, exact current grants or metadata.
- The authorized reviewer must match source row to current identity privately using approved least-privilege read-only evidence; do not publish the person's details in a public PR, issue, CI log or test fixture. No production account is queried or modified.

## Role, authorization and negative HTTP matrix

| Boundary | Source code / automated assertion | Open acceptance |
| --- | --- | --- |
| Built-in catalog | `B2C_DRIVER` is globally scoped and seeds the single `deliveries.b2c.execute` permission; `User::inheritedPermissionCodes` agrees | Check the current real account's effective assignments and overrides privately |
| No accidental administrative powers | No `orders.view`, `inventory.view`, `roles.manage`, `users.delete` or B2B/Van grants; no store inventory scope; permission-scoped Security, Retail inventory and Wholesale order leaves are absent from Sidebar; direct Security/Wholesale HTTP denied in AR and EN. Legacy global-role ERP *group* discoverability requires separate correction (see below) | Real account's current Sidebar and direct-URL AR/EN, RTL/LTR, responsive and a11y verification |
| Driver App impersonation | Login without an active Retail `drivers` profile returns 403; direct Driver wallet with a driver app token returns 403; a Wholesale profile cannot convert B2C role into B2B delivery authority; account and historical rows retained | Verify real account's current profile, active flag, store channel and device-token/session revocation |
| Retail Driver positive path | Authorized synthetic `B2C` profile can log in and create the app-specific Driver token | Isolated staging Driver UI and current account authorization |
| Explicit user DENY | Global `deliveries.b2c.execute` DENY defeats the role grant for new Driver login and existing authenticated Driver API access; no extra token is created | Review real current user overrides, approvals and audit provenance |
| Van App isolation | Retail Driver cannot access Van visits, whether using a Driver or Van token in synthetic tests | Van runtime role, assignment and channel review |
| Fulfillment | No routing, assignment, settlement or monetary writes are changed; canonical B2B=VAN ONLY / B2C=DRIVER ONLY remains intact | Independent full V2 end-to-end acceptance |

**Tests:** `backend/tests/Feature/V2ExportedB2CDriverBoundaryTest.php`. Run with `php artisan test --filter=V2ExportedB2CDriverBoundaryTest` in a CI/developer environment. This is **not** an instruction for end users to run Terminal commands on a Laravel production host. A passing targeted test alone is not a completion certificate: require Repository Policy, FOODEX V2 Isolation Gate, full Required CI Gate and relevant dependency/upgrade checks on the exact final PR SHA before merge.

## Per-application impact
- **Backend/API:** regression-only coverage of existing `AuthController`, `EnsureDriverRuntimeAccess`, `EnsureVanRuntimeAccess`, effective role/override and tenant-scope contracts; **no API shape, route, policy, migration, grant or production code changes**.
- **Dashboard:** current authoritative Sidebar and HTTP denial exercised in Arabic and English. No UI/source update. **Outstanding discoverability gap:** on current Foodex_V2, `AdminNavigation::canAccessPlatformErp` checks for any active global role, including a global Driver role. This can expose broad ERP/Administration entry links even though the actual protected HTTP routes and permissions remain denied. The new test deliberately asserts the absence of forbidden *permission-scoped business leaves* and denied direct HTTP, **not** an unsupported empty Sidebar. The shared `backend/app/Support/AdminNavigation.php` is currently owned by open V2 PR #3064 (Issue #2713); do not collide with its worker. Coordinate a separate non-overlapping correction and new role-only navigation regression before marking #2908 complete. Independent browser RTL/LTR/responsive/keyboard/screen-reader acceptance remains pending.
- **Customer:** **not affected** — no Customer API, payload, customer identity, catalog, checkout or Flutter code change.
- **Driver:** **no changed app contract** — regression exercises existing B2C Driver login and runtime guard; no Flutter, API schema, push, GPS, assignment or dispatch modification. Separate mobile issue is unnecessary for this test-only change, but mobile runtime UAT is unverified.
- **Van:** **not affected** — denied Driver cross-app access is asserted; no Van assignment, visit, payment, or Flutter behavior changed.
- **Finance / SoD:** no financial mutation or clearance. Treat this as an account-role boundary test, not a real user's financial SoD audit.

## Delivery and handoff
- One issue-scoped branch: `test/2908-v2-exported-b2c-driver-role-boundary`; one PR whose **base must be Foodex_V2**, with exact-head required CI evidence, no automatic promotion to main.
- **Keep #2908 OPEN** after integration until the historical account is reconciled to current authorized evidence, the over-broad platform ERP entry visibility for role-only Driver accounts is corrected without cross-worker collisions, and real scoped/role/tenant/Sidebar/browser acceptance is complete. A synthetic role-level test cannot establish the private account's current status.
- No main commit, PR, merge, tag, release, signed ZIP publication, cPanel or production deployment is authorized.
