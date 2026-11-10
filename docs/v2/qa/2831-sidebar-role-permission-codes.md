# V2 #2831 — P094 Page Tree checkboxes use canonical RBAC Permission Codes

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P094. Parent #2503.
**Branch:** `feat/2831-v2-sidebar-permission-code-editor` → **`Foodex_V2` only**. No main merge, production deploy, publication, tag or release.

## Placement & authorization decision
- Domain: Governance / Security. Entity: role permission assignment. Persona: authenticated authorized `roles.manage` administrator. User goal: change which permission codes a custom role holds, not a cosmetic nav flag.
- Workflow: occasional privileged configuration with explicit confirmation and immediate authoritative readback. Entry: existing Administration > Page Tree & Permissions; canonical GET `admin.sidebar-access.roles`.
- Placement: **EXTEND** this existing management page, not a new route or second RBAC engine. Navigation type: `ADMIN_SETTINGS`; existing Sidebar projection and server-side route guards remain authoritative.
- Mutation: existing `PATCH admin.security.roles.update` → `SecurityController::updateRole` → `RbacManager::updateRole` (role immutability, built-in protection, actor grant ceiling, domain scope, atomic permission sync, audit reconciliation).
- The page maps registered sidebar items to real permission codes from `Permission` records, renders a permission once when multiple pages share it, and retains all unrelated existing permission IDs. It does **not** write `admin_sidebar_role_visibility`.
- Historical role visibility overrides, admin layout, readiness/module entitlements, selected tenant/store context, SoD rules, HTTP route authorization and deny logic stay independent. An old explicit hidden preference can still suppress an otherwise authorized link and is disclosed by a status badge; granting an RBAC code does not promise visibility or operational readiness. The historical layer remains for #2860/P137 migration.
- `SUPER_ADMIN`, `PLATFORM_OWNER`, job-role catalog built-ins, archived roles and roles outside the actor's grant ceiling are read-only in this page. An attacker directly posting to canonical role update still passes its server-side authorization/validation; no trust in a disabled checkbox.
- Field types: role selector = authoritative lookup, permission choices = authoritative enum/lookup keyed to `permissions`; raw codes shown only as subordinate advanced diagnostics, not typed inputs. No new schema or new API endpoint.

## Security, localization and acceptance matrix
| Surface | Result |
|---|---|
| Backend | Existing `Permission` and `RbacManager` are source of truth; unchanged role update endpoint and database contract. |
| Dashboard | Existing shared shell, header, Sidebar, role selector; AR/EN translations, RTL/LTR, native keyboard/checkbox and accessible 44px labels, confirmation and error/status surfaces. Grid already reflows to one column on mobile. |
| Customer app | **Not affected**: no API/mobile route/auth response, schema, token or Flutter changes. The Customer login/session permissions remain unchanged. |
| Driver app | **Not affected**: no mobile endpoint/dispatch/routing or Flutter changes; B2C Driver-only invariant untouched. |
| Van app | **Not affected**: no mobile endpoint/dispatch/routing or Flutter changes; B2B Van-only invariant untouched. |
| Negative path | Role-less actor GET/PATCH 403; role immutability and archived/built-in restriction through `RbacManager`; retail role cannot acquire wholesale grants even when directly forged; no appearance-only pseudo-permission. |
| Persistence | Existing unrelated grants retained, one checkbox per shared code, unrelated sidebar-visibility preferences untouched, role change audit recorded. |
| Rollback | Revert only branch UI/controller/translations/tests; existing persisted real RBAC role changes must be restored through the audited role editor, never by silently resetting database permissions. |

## Required verification (do not infer pass)
- Focused: `cd backend && php artisan test --filter=V2SidebarRolePermissionCodesTest`.
- Existing: `V2SidebarGroupPermissionBoundaryTest`, role editor/RBAC, Sidebar role visibility, Laravel tests and exact route/permission HTTP checks.
- Repository Policy, FOODEX V2 Isolation Gate, PHP/Pint/PHPStan/security, AR/EN Localization Quality, Required CI Gate and MySQL/Redis install/upgrade/recovery on the **exact PR head**.
- Functional AR/RTL + EN/LTR, desktop 1280 and 1024, tablet 768, mobile web 430/412/390/360/320, keyboard/focus/touch and authorized/forbidden states: actual isolated V2 staging acceptance must be recorded, not inferred from responsive CSS or CI-only results. Screenshots are optional per #2472.

**Status at code authoring:** pending required CI and isolated branch integration. Keep Issue open if any mandatory acceptance remains unverified. 
