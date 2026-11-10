# V2 #2712 — Procurement Sidebar persona security/route contract

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), `T353R3`; parent #2503.
**Integration only:** `Foodex_V2`; branch `test/2712-v2-procurement-sidebar-persona-contract`; no main, release, tag, cPanel or production.

## Placement and authority

- Domain/entity: Wholesale enterprise procurement, suppliers and purchase documents.
- Actor: Procurement Manager (`PROCUREMENT_MANAGER`), optionally combined with a legitimate Wholesale B2B management role when broader store navigation is necessary. **Role display label never creates a permission.**
- Goal: authorized overview, procurement/suppliers, available inventory and reports. These are capabilities, not a reason to create five duplicate top-level Sidebar groups or unrestricted screens.
- Reuse the existing canonical `admin.erp.procurement.index` workbench with `suppliers`, `requests`, `orders`, `reports` tabs; enterprise modules reuse `AdminNavigation::routeItemEnterpriseModule`, not a parallel menu. The canonical ERP Center remains informational and the Action Center is an available overview.
- Discoverability: current authorized `erp_procurement` link appears in the existing Catalog/Procurement context only after exact-version `ModuleOperationalReadiness` + module ENABLED + entitlement and action permission; `reports_center` uses existing reports permission. Inventory/WMS access depends on separate `inventory.view` and its own readiness, not simply Procurement Manager role text.
- Existing route/permission: `admin.erp.procurement.index` + `procurement.suppliers.view`; module code `procurement`; B2B store scope and entitlement enforced server-side. Financial SoD/maker-checker enforcement remains with canonical services; this scoped change is read-only test+QA, not a new transaction flow.

## Acceptance evidence

- `backend/tests/Feature/V2ProcurementSidebarPersonaContractTest.php` verifies seeded real role permissions (not role names as authority), AR/EN ERP Center and real disabled-route HTTP 403, no Retail/Finance/WMS escalation, allowed+denied Procurement route with an actual Wholesale store and enabled/entitled/exact-certified module, and store-role-only rejection.
- Validate under current exact-head CI: Repository Policy; V2 Isolation; Laravel syntax/Pint/PHPStan + security/OpenAPI; Laravel Feature suite; Arabic/English quality; MySQL/Redis setup, upgrade and recovery; final required-ci-gate.
- Independent V2 staging browser acceptance still pending before Issue closure: Arabic RTL/English LTR, 320/360/390/412/430/768/1024/1280px, keyboard/focus/screen-reader, Procurement Manager/Buyer/Approver and Retail-only personas, module disabled/stale/unhealthy checks, cross-tenant B2B vs Retail direct GET/write tests, and real Procurement G0–G13 transaction lifecycle. This test-only change must NOT be described as completing those runtime tests or production readiness.
- No new role grants, translations, routes, module certification defaults, schema, scheduler, financial ledger or deliveries. Existing module contracts and backend middleware unchanged.

## Cross-surface impact

| Surface | Classification | Rationale |
| --- | --- | --- |
| Laravel Backend | Acceptance regression only | Exercises existing authorization, version attestation, entitlement and scope; no runtime source or API change |
| Dashboard | Acceptance regression only | Existing authorized Sidebar/ERP workbench and bilingual layout; independent physical-browser QA pending |
| Customer Flutter | Not affected | No shared API, role, deep-link, order, push, credit, or Flutter source change |
| Driver Flutter | Not affected | No B2C Driver-only dispatch/task/GPS or mobile/API change |
| Van Flutter | Not affected | No B2B Van-only order/visit/assignment or mobile/API change |

Issues and PRs for unrelated worker-owned Sidebar/ERP module changes are not duplicated here. Only actual verified acceptance permits closing #2712.
