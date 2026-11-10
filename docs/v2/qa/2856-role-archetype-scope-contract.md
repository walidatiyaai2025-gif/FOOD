# V2 #2856 / P133 — Organizational role archetypes and scope authority

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P133. Parent #2503. Integration target: **Foodex_V2 only**. This is an isolated code contract and gap ledger, **not production authorization or owner sign-off**.

## Current canonical decision boundary

The existing `BuiltInJobRoleCatalog` treats `scope` as an **enforced assignment scope**, currently only `global` or `store`, while `business_scope` is descriptive. `Role` database scope and `User::hasPermission` remain server authorities. Do not interpret a displayed job title as independent permission, company, branch or warehouse ownership.

| Business role / archetype | Existing assignment authority | Required boundary |
|---|---|---|
| Wholesale/company `B2B_ADMIN` | Global + B2B channel rules | Cannot automatically grant Retail store access or platform identity lifecycle |
| Warehouse manager `WAREHOUSE_MANAGER` | Currently global/tenant descriptor; `inventory_wms` required | Warehouse-specific record scope is **not** yet an independently assignable RBAC scope; module entitlement and domain services must still authorize |
| Retail store inventory `RETAIL_INVENTORY` | Store assignment, restricted to Retail profile | Only the exact assigned store; no global grant, no foreign-store HTTP, no role-template management |
| Template/security steward `SECURITY_ADMINISTRATOR` | Global/platform role catalog | A display or template code cannot bypass permission, approval, SoD, audit, or protected built-in-role rules |

## Isolated implementation evidence

New `backend/tests/Feature/V2RoleArchetypeScopeContractTest.php` checks:
- Canonical role-definition metadata vs seeded Role database `scope` and Arabic/English presentation.
- Store A positive `inventory.view` authorization and store B, B2B channel, global and Security Admin direct-route denial for the same scoped user, in AR/EN with RTL/LTR rendered response.
- Global Wholesale role does not become Retail store access; deactivated roles stop contributing permission and direct HTTP access.
- Warehouse role is explicitly linked to `inventory_wms` and cannot be mislabeled as a warehouse-assignment primitive.

These tests use temporary seeded actors and stores. No live accounts or business data are changed.

## Cross-surface and placement assessment

- Backend: **Affected**, test-only contract; no authorization implementation altered.
- Dashboard / Sidebar: **Affected as audited existing behavior**, no new page/navigation; existing Security/Roles route must remain 403 for non-privileged roles. Reuse, not new screen.
- Customer Flutter: **No source/API contract change** (role catalog is for Dashboard staff).
- Driver Flutter: **No source/API contract change** (B2C Driver and B2B Van-only dispatch preserved).
- Van Flutter: **No source/API contract change** (no dispatch, van permission or API change).
- Existing AR/EN translations are read; no new user-facing text added.

## Open governance/acceptance gates — DO NOT close issue prematurely

1. Obtain owner-approved role-template steward and approval/delegation model, including maker/checker and audit on edits.
2. Resolve company, branch and warehouse *record-level* scope semantics before inventing database role assignment scopes; existing global/retail-store scopes do not imply them.
3. Validate real tenancy/company/branch/warehouse record access with isolated data and authorized manager personas, especially WMS module entitlement/readiness.
4. Run exact-head Repository Policy, FOODEX V2 Isolation Gate, backend tests/Pint/PHPStan, localization, MySQL/Redis acceptance and verify all child jobs GREEN before merging code **only into Foodex_V2**.
5. Perform independent real-browser Dashboard role-template access, responsive and RTL/LTR verification before final functional acceptance.
6. Preserve the master Issue #2503 OPEN. No main merge, production deploy, tags, releases or cPanel actions authorized.

**Delivery status:** isolated regression contract implemented; final governance and runtime acceptance are still pending until separately evidenced.
