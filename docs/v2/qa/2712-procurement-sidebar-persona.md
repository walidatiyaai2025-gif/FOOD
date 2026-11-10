# V2 #2712 — Procurement Sidebar persona security/route contract

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), `T353R3`; parent #2503.
**Integration only:** `Foodex_V2`; branch `test/2712-v2-procurement-sidebar-persona-contract`; no main, release, tag, cPanel or production.

## Placement and authority

- Domain/entity: Wholesale enterprise procurement, suppliers and purchase documents.
- Actor: active Wholesale Procurement Manager (`PROCUREMENT_MANAGER`), Buyer (`PROCUREMENT_BUYER`) or Approver (`PROCUREMENT_APPROVER`) under existing business permissions. None should require a blanket `B2B_ADMIN` grant just to access their own enabled Procurement workspace. **Role display label never creates a permission.**
- Goal: authorized overview, procurement/suppliers, available inventory and reports. These are capabilities, not a reason to create five duplicate top-level Sidebar groups or unrestricted screens.
- Reuse the existing canonical `admin.erp.procurement.index` workbench with `suppliers`, `requests`, `orders`, `reports` tabs; enterprise modules reuse `AdminNavigation::routeItemEnterpriseModule`, not a parallel menu. The canonical ERP Center remains informational and the Action Center is an available overview.
- Discoverability: current authorized `erp_procurement` link appears in the existing Catalog/Procurement context only after exact-version `ModuleOperationalReadiness` + module ENABLED + entitlement and action permission; `reports_center` uses existing reports permission. Inventory/WMS access depends on separate `inventory.view` and its own readiness, not simply Procurement Manager role text.
- Existing route/permission: `admin.erp.procurement.index` + `procurement.suppliers.view`; module code `procurement`; B2B store scope and entitlement enforced server-side. Fixed `backend/config/admin.php` so only the three existing Procurement job role codes join the B2B *channel role ceiling*, retaining their distinct built-in permission bundles and required-module availability checks. No Retail (`b2c`) global role whitelist change. Financial SoD/maker-checker enforcement remains with canonical services; this change does not create a transaction flow or approve any financial action.

## Acceptance evidence

- `backend/tests/Feature/V2ProcurementSidebarPersonaContractTest.php` verifies fail-closed Procurement and Reports grants while the required module is disabled, AR/EN informational ERP Center, direct unready HTTP 403, and a legitimate Procurement Manager's real Wholesale store GET with exact enabled/entitled/certified module and no need for `B2B_ADMIN`. It checks no Finance/WMS permission creep and explicit Retail-only 403 even after the Wholesale Procurement module is fully ready. Existing full Procurement workbench tests independently cover supplier→request→approval→order lifecycle.
- Validate under current exact-head CI: Repository Policy; V2 Isolation; Laravel syntax/Pint/PHPStan + security/OpenAPI; Laravel Feature suite; Arabic/English quality; MySQL/Redis setup, upgrade and recovery; final required-ci-gate.
- **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under owner #2503. Isolated V2 browser/device checks (Arabic RTL/English LTR, 320–1280px, keyboard/focus/screen-reader), actual Procurement Manager/Buyer/Approver and Retail-only personas, cross-tenant read/write testing and end-to-end Procurement G0–G13 lifecycle remain tracked centrally in #2503/#2750. CI can close the implementation leaf once true source behavior and affected app gates pass, but never proves staging/UAT or production readiness.
- No new permission definitions, persisted role assignments, translations, routes, default module certification, migrations, scheduler, financial ledger or deliveries. The intentional B2B role-channel admission is **not** a new direct HTTP authorization bypass; module readiness, effective per-store RBAC, company scope, entitlements, SoD and audit remain required.

## Cross-surface impact

| Surface | Classification | Rationale |
| --- | --- | --- |
| Laravel Backend | Affected | Channel role ceiling now recognizes three existing Wholesale Procurement roles, bounded by their permissions, module readiness, entitlement and store/tenant guards; tests assert positive and denied HTTP |
| Dashboard | Affected | Procurement persona may now enter its real authorized B2B workbench and see its existing certified Sidebar link; no new screen or unrestricted navigation |
| Customer Flutter | Not affected | No shared API, role, deep-link, order, push, credit, or Flutter source change |
| Driver Flutter | Not affected | No B2C Driver-only dispatch/task/GPS or mobile/API change |
| Van Flutter | Not affected | No B2B Van-only order/visit/assignment or mobile/API change |

Issues and PRs for unrelated worker-owned Sidebar/ERP module changes are not duplicated here. After implementation and all exact-head required CI gates pass, follow the updated owner leaf-closure rule in #2503; preserve final staging acceptance as pending and never infer authority to merge into `main` or deploy to production.
