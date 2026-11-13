# V2 #2568 / P238 — Procurement Sidebar group

## Source and scope
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P238, parent #2503.
- Integration: **Foodex_V2 only**. No main, tags, release, deployment or cPanel.
- This leaf relocates the existing **Procurement** business entry; P237 inventory/procurement/warehouses parent and P253 warehouses group remain separate.

## Canonical placement / UX
- Domain: Procurement & Inventory; entity: Supplier / Purchase Request / Purchase Order / GRN / Invoice / Return.
- Actor: authorized ERP procurement operator with effective store permission; role/access context remains server-owned.
- User goal: enter the existing procurement workbench to manage authorized procurement activities.
- Workflow: frequent operational multi-tab workbench; data density high; parent: current ERP Center and Procurement workbench.
- Decision: **Reuse** `admin.erp.procurement.index` with `EnterpriseProcurementController`; navigation type **SIDEBAR** business group `procurement`, one existing child `erp_procurement`. No new route, screen, form, table, seed or endpoint.
- Navigation owner: `AdminNavigation::groupsFor` + `AdminSidebarManager` registry; exact page source remains `backend/resources/views/admin/enterprise-procurement.blade.php`.
- Permissions: existing `procurement.suppliers.view` for entry, exact tab and action permissions in controller; retail store scope / tenant context; `procurement` lifecycle, enterprise entitlement, operational readiness. Empty groups disappear; menu is never a permission grant. Direct HTTP is guarded by management middleware, `EnsureErpOperationalReadiness`, controller store permissions and scope.
- Desktop: existing authorized right/left domain Sidebar and canonical workbench tabs; mobile web/tablet: same off-canvas navigation and responsive workbench; Arabic RTL and English LTR use `admin.nav_groups.procurement`.
- States: available, permission-denied, disabled/unready, empty; existing workbench handles loading/no-data and record states. The ERP Center remains a second **entry point to the same renderer**, not a duplicate implementation.
- Fallback/back: ERP Center (`admin.erp.index`) / canonical Dashboard navigation.

## Per-app impact
- **Backend/API:** Dashboard navigation/layout registry and test expectations only; REST v1 contract and data schemas unchanged.
- **Dashboard:** new Procurement *group* displays only the existing authorized `erp_procurement` link, not a new page. Catalog and Warehouses keep their existing own children.
- **Customer app:** not affected; no changed customer endpoint or product/pricing data contract.
- **Driver app:** not affected; no dispatch/status or B2C delivery contract change.
- **Van app:** not affected; no assignment/stock/visit or B2B van dispatch contract change.
- Invariants: B2B=VAN ONLY, B2C=DRIVER ONLY; store-scoped roles and financial maker/checker untouched.

## Verification / acceptance
- `V2ProcurementSidebarGroupAuthorityTest`: AR+EN real HTTP and one authorized entry, roleless direct deny, foreign-store 403, disabled module 403; catalog/warehouse isolation.
- `V2BusinessDomainSidebarGroupingTest`, `V2ErpBusinessDomainNavigationTest` and `ErpCenterTest` reconcile canonical group assertions while retaining all route/permission contracts.
- Required on exact PR head: Repository Policy, FOODEX V2 Isolation, Laravel backend/format/static/security, localization, MySQL deployment acceptance, Required CI Gate and applicable jobs.
- **Manual V2 cross-persona mobile-web/desktop visual UAT remains required** by #2503/#2750; no production readiness claim is made by this documentation.
