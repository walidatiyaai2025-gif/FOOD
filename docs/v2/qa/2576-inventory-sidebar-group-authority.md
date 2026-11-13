# V2 #2576 — Inventory / المخزون Sidebar group (P246)

## Owner and source
- Parent #2503; source: FOODEX Unified RBAC/ERP/Sidebar, Procurement & Inventory line P246. Foodex_V2 only.
- One implementation branch: `feat/2576-v2-scoped-inventory-navigation`. No main integration, release, tag, production or account changes.

## Placement and route authority
- Domain: Inventory; entity: scoped current inventory; actors: authorized Retail inventory/store operators and Wholesale inventory operators.
- User goal: enter existing inventory views; workflow: repeat read/adjust through the already protected original workspace, not a new page.
- Decision: **Reuse** existing `admin.b2c.module?module=inventory` and `admin.b2b.module?module=inventory`. These routes/Blade controllers and exact-user authorization are authoritative.
- Navigation: `SIDEBAR`, new business **group** `inventory` with existing child keys `b2c_inventory` and `b2b_inventory`; no duplicate routes/actions. The sibling `warehouses` group remains the WMS `tab=warehouses` owner from #2583, and Catalog retains products/pricing.
- Registration: AdminNavigation and AdminSidebarManager registry agree on group/child ownership. Saved layouts remain presentation-only; they cannot grant access. Empty groups disappear.
- Entry point: Dashboard global sidebar; canonical landing: corresponding authorized channel inventory module; back path: existing Dashboard sidebar/module tabs. Desktop/tablet/mobile web reuse the responsive original shell. AR/RTL: `المخزون`, EN/LTR: `Inventory`.

## Authorization and technical scope
- No changes to underlying inventory, products, prices, fulfillment, Ledger, API, DB or permissions. Existing `module()` checks channel and `inventory.view` before emitting links; actual channel controllers independently enforce authentication, store/business profile/role and 403/404 failure.
- Authorized B2B operators should not need a Customer Service persona just to discover their inventory. B2C roles remain constrained by active owned Retail stores, and Super Admin's ordinary Retail workspace hiding is preserved.
- Module readiness is still enforced by the existing destination runtime where applicable. This new group does not expose WMS operations or change entitlement lifecycles.
- Existing `AdminNavigationAuthorizationAuditTest` opens all visible targets; new `V2InventorySidebarGroupAuthorityTest` verifies group registry, two real HTTP destinations, AR/EN direction, isolation, cross-store denial and roleless denial. `CustomerServiceManagerWorkspaceTest` is updated to assert Catalog/Inventory separation without loss of permissions.

## Surface impact matrix
| Surface | Impact |
| --- | --- |
| Laravel Backend | Sidebar composition and immutable registry placement only; existing HTTP routes unchanged |
| Dashboard | Visible Inventory group with scope-filtered B2B/B2C links and existing responsive UI |
| Customer | No API, checkout, stock or pricing contract change; existing B2B/B2C client workflows unchanged |
| Driver | No API, dispatch, inventory or notification contract change; B2C Driver-only invariant unaffected |
| Van | No API, dispatch, consignment, offline or stock contract change; B2B VAN-only invariant unaffected |

No mobile issue created because no mobile/API behavior changes. AR/EN labels, RTL/LTR, keyboard/accessibility and responsive layout rely on the existing shared navigation renderer.

## Acceptance
Focused and full Laravel tests, Pint/PHPStan, AR/EN localization, Repository Policy, FOODEX V2 Isolation, MySQL/Redis install-upgrade-recovery and terminal required-ci-gate must all be SUCCESS on the exact final PR head before merge. Validate merge base is Foodex_V2. Manual end-to-end final V2 staging UAT belongs to parent #2503; any unverified required code/source item blocks merge.

Do not infer production readiness from this navigation-only change.
