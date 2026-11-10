# V2 #2827 — Scoped user permission Inherit (P090)

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P090, parent #2503. Isolated target: `Foodex_V2` only.

## Authority and placement
- Domain: Administration / security / RBAC; entity: user permission overrides.
- Actor: authenticated manager with `users.roles.manage`; platform owner and SUPER_ADMIN target protections remain enforced.
- Goal: return the user's managed overrides in **one** global or assigned Retail store scope to the current inherited role baseline.
- Canonical entry: Administration → Security → Users → Manage → Individual user permissions (global/store). Existing `admin.security.index`, existing `PUT admin.security.users.permissions`. Navigation type: **ADMIN_SETTINGS**, placement **EXTEND** existing modal/form; no new screen, Sidebar item, API route, permission, or role assignment.
- Workflow: intentional `Inherit from role` button, confirmation, authorized server mutation, persisted readback and audit. Enum `inherit=1` is a privileged action mode, not a user-entered technical key.
- Permissions: independent `users.roles.manage` gate, target cannot be Platform Owner, SUPER_ADMIN target override lock, validation of active global-role or exact assigned store context, actor grantable permission ceiling; unauthorized direct PUT remains 403. Tenant/module/readiness: the live `inheritedPermissionCodes` role/catalog/module/entitlement state is authoritative; no stored copy of inherited grants.
- UI: paired AR/EN labels and confirmation; native accessible button (44px touch); existing responsive Security modal/table layout, RTL/LTR and shared Admin shell. UI is unchanged outside the existing user editor.

## Semantics and data preservation
The existing checkbox form still writes minimum allow/deny differences against the current inherited baseline. Explicit `inherit=1` ignores submitted checkboxes, synthesizes the current inherited selection **only for permissions the actor is authorized to manage**, and uses the existing transaction to delete differences only at `scope_key=global` or `scope_key=store:{id}`. Other scoped overrides, assignments, audit history and user data are preserved. `has_permission_overrides` is recalculated from durable rows. Permission changes in the role, module readiness or entitlement are evaluated at read time, not frozen into an individual ALLOW.

## Impact matrix
| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend RBAC + Dashboard | Affected | Existing authenticated Security form, controller and `RbacManager` method; no new public API; three focused feature tests |
| Dashboard mobile web | Affected | Same existing modal form; localized native 44px action and confirmation; real device/keyboard acceptance pending |
| Customer Flutter | Not affected | No Flutter, public API, login/permission token, checkout or customer route contract modified |
| Driver Flutter | Not affected | No driver API/fulfillment/dispatch/Flutter code; B2C remains Driver only |
| Van Flutter | Not affected | No van API/fulfillment/dispatch/Flutter code; B2B remains Van only |

## Tests and acceptance
New `backend/tests/Feature/V2UserPermissionInheritModeTest.php` covers global inherited restore and live role-template changes, AR/EN B2C store scoped restore preserving foreign-store DENY, authorized URL rendering, cross-store rejection and unauthorized direct PUT 403. Existing `V2CustomRoleOverrideIsolationTest`, `V2AllowScopeReadinessRegressionTest` and backend security suite remain applicable.

Required: exact PR-head Repository Policy, V2 Isolation, Laravel/Pint/PHPStan/Localization, MySQL/Redis setup/upgrade/recovery and `required-ci-gate` green before integration. Independent staging browser/mobile 320–1280px keyboard/screen-reader confirmation is **not proved by CI** and must be recorded before final Issue closure. No production deploy, releases, main changes or version promotions.
