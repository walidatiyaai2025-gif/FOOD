# V2 #2583 — Warehouses / المستودعات (P253)

## Source and placement

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Procurement & Inventory, legacy P253. Parent #2503; destination `Foodex_V2` only.
- **Reuse → Compose:** the existing `admin.erp.wms.index` Workbench already owns the authorized warehouses child `tab=warehouses` shipped through Issue #2584/P254. This PR does not create another warehouse table, route, ledger, controller or screens.
- Navigation type: **SIDEBAR business group** `warehouses` / `admin.nav_groups.warehouses`, with one live canonical child `erp_wms`. AdminSidebarManager registry and runtime AdminNavigation agree on grouping. The standard group filter removes empty unauthorized groups. Saved administrator layout remains a presentation-only decision, not an access grant.
- Actual child: `/admin/erp/wms?tab=warehouses`. Back and deeper work remain inside the WMS page tabs, including accessible exact-warehouse overview link. No false stock quantity.
- Original Catalog & Pricing group retains product/inventory/pricing workspaces, but no longer duplicates the WMS warehouses item.

## Effective authority

- Navigation uses existing `routeItemEnterpriseModule`: exact `inventory.view` scoped permission, `inventory_wms` module state/readiness/version certification, entitlement resolver, `admin.erp.wms.index` route availability.
- The actual WMS controller retains StoreBusinessProfile/store/channel validation and `WarehouseScope::authorizedWarehouseIds` filtering; direct unauthorized route returns 403/404. An item label or saved visibility cannot grant a warehouse.
- Both global ERP operators and authorized Retail inventory staff can see the page when their server-side permission/entitlement/readiness allow it. A Retail store-role does not become a global B2B actor.
- Disabled/unready WMS hides ordinary warehouse group; Platform Owner diagnostics remain possible via the **existing** module inspector flow, not a false-ready business page.
- `backend/config/admin_sidebar.php` registers `warehouses` group and gives WMS item the correct `scope=both` for global and authorized Retail store workers.

## Tests and UX

- `V2WarehouseSidebarGroupAuthorityTest`: scoped WMS group/child route and P254 tab, no catalog duplication, AR/EN and RTL/LTR actual HTTP, denied role/no empty group/direct 403, disabled module fail closed while warehouse rows persist, read-only no stock movements.
- Child source `V2WmsWarehousesPageAuthorityTest` already verifies warehouse-specific scope, foreign stores, multi-role explicit DENY, disabled module and store entitlement.
- Responsive: no new renderer; existing accessible WMS responsive cards, native links/tabs, field labels, focus and user-selectable keyboard navigation retained. Arabic and English group labels are catalog-based.
- Customer: no changed API/authorization/nav and no Flutter change. Driver: no changed B2C-only dispatch/auth/stock API. Van: no changed B2B-only VAN dispatch/consignment/offline API. No new app issue; the WMS group is Dashboard-only.
- CI: verify latest PR head Laravel full suite, PHP/Pint/PHPStan, AR/EN, V2 Isolation, repository policy, MySQL/Redis install/upgrade/recovery and terminal required-ci-gate SUCCESS before merge. Final visual/UAT acceptance remains under parent #2503.

No main merge, release/tag, cPanel or production deployment.
