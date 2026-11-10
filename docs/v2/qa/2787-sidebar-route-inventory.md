# V2 #2787 / T037R01 — Authorized Sidebar Route / Action Inventory

**Integration:** `Foodex_V2` only. **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Section 7, Stage A, Table row T037R01. Parent #2503.

## Delivery

New `App\\Support\\V2SidebarRouteInventory::forUser(User $user)` is a **read-only diagnostic inventory** composed from the authoritative `V2SidebarMetadata::forUser()` / `AdminNavigation::groupsFor()` projection and the actual registered Laravel routes. It **never** creates an authorization grant or tries to infer route access from Sidebar layout/visibility. It does not expose an unauthenticated endpoint, persist a snapshot, accept arbitrary user/route parameters, or change configured roles.

Each authorized **item** has these explicit runtime fields:

| Inventory property | Source and meaning |
| --- | --- |
| `group_key`, `item_key`, `parent_item_key` | Current authorized Sidebar tree, including one child level |
| `label_ar`, `label_en` | Existing translated label data (may be null for legitimately untranslated item metadata) |
| `route`, `route_uri` | Real named Laravel Route and registered URI pattern; a stale/unregistered route is excluded |
| `http_methods` | Actual Laravel GET/POST/etc route methods, with redundant HEAD removed |
| `controller_action` | Real Laravel route action (controller method or registered invokable action), not an inferred Sidebar description |
| `permission`, `permissions`, `permission_mode` | Canonical item permissions (one/all/any), **not** the route's complete state-transition action permissions |
| `scope`, `channel` | Existing item scope and B2B/B2C channel metadata; actual record/store scope remains enforced by the route/service |
| `module`, `readiness` | Existing enterprise module metadata and diagnostic-only vs guarded projection; not proof that a module has passed an operational readiness gate |

**Policy:** A row appears only after `AdminNavigation` authorizes it for a real actor. A permission field is descriptive metadata and **does not** independently authorize GET/POST/approve/dispatch/finance actions. Any missing method/action, readiness check, tenant scope or controller-specific authorization requires route-by-route review rather than inventing a permission mapping.

## Representative route/action/permission inventory

The following contract rows are verified by new feature tests against the running Laravel router rather than being assumed static strings:

| Persona | Sidebar item | Named Route | Permission | Scope | Channel | Result |
| --- | --- | --- | --- | --- | --- | --- |
| Wholesale `B2B_ADMIN` | `catalog_management` | `admin.catalog.index` | `catalog.view` | `global` | canonical actor scope | In inventory; GET permitted |
| Retail `RETAIL_INVENTORY` at Store A | `b2c_inventory` | `admin.b2c.module` | `inventory.view` | `store` | `b2c` | In inventory; GET permitted at Store A |
| Same Retail actor at Store B | `b2c_inventory` | `admin.b2c.module` | `inventory.view` | `store` | `b2c` | Direct Store B GET 403/404; Sidebar record never grants Store B |
| Same Retail actor | `b2b_inventory` | `admin.b2b.module` | `inventory.view` | `global` | `b2b` | Absent; direct Wholesale GET 403 |
| Roleless actor | `catalog_management` | `admin.catalog.index` | `catalog.view` | `global` | — | Absent; direct GET 403 |
| Wholesale actor with explicit global `catalog.view` DENY | `catalog_management` | `admin.catalog.index` | `catalog.view` | `global` | B2B actor | Absent; direct GET 403 |

The `controller_action`, `route_uri`, and methods are resolved by the runtime named-route collection, rather than maintained as potentially stale hard-coded metadata. `V2SidebarRouteInventoryTest` verifies every emitted row's existing named Route, non-empty action/methods, permitted scope/mode/readiness vocabulary, and equality with the canonical Sidebar metadata item-key projection in Arabic and English.

## Security, UX, app impact

- **Backend:** Read-only inventory class and feature regression tests. No migrations, API endpoint, grants, activity records, role changes, cache or queue behavior.
- **Dashboard:** Current Sidebar/navigation is consumed but not modified. Existing pages and user workflows remain authoritative. This technical inventory has no new user-facing screen (no navigation addition requested for the documentation audit).
- **Customer:** NOT AFFECTED — no Flutter/API/auth/customer pricing/deep-link contract change.
- **Driver:** NOT AFFECTED — no Flutter/API/GPS/driver dispatch/lifecycle changes. B2C=DRIVER ONLY unchanged.
- **Van:** NOT AFFECTED — no Flutter/API/Van visits/fulfillment/collections changes. B2B=VAN ONLY unchanged.
- **Finance/SoD:** Inventory does not grant or imply `finance.post`, approval limits, settlement or maker/checker authority. Those remain independent runtime controls and tests.

## Acceptance, limitations and handoff

The inventory is an **authorized runtime projection**, not a system-wide independent proof that **every route** in Laravel, including POST/PUT/PATCH/DELETE actions never linked from Sidebar, is correctly authorized. A complete repository-wide route/action/permission inventory and independent stage-D role-vs-route/action cross-tenant matrix must be audited before closing the master #2503. No runtime DB query on a user account is a production authorization attestation.

This PR can be integrated into `Foodex_V2` after exact-head Repository Policy, FOODEX V2 Isolation, Localization, Laravel/Pint/PHPStan/security/OpenAPI, and real MySQL/Redis fresh install/upgrade/recovery `required-ci-gate` PASS. **Issue #2787 remains OPEN** until isolated staging role-specific browser AR/EN RTL/LTR responsive/accessibility, inventory completeness, operational module/entitlement/readiness, and route/state-transition audit evidence are independently accepted. No main, releases, APK, tags, cPanel or production.
