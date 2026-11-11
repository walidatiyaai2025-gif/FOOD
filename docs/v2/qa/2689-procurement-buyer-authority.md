# V2 #2689 — PROCUREMENT_BUYER real operational authority

- **Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), T122R9.
- **Parent:** #2503, owner-approved isolated delivery to **Foodex_V2** only.
- **Actor:** Built-in PROCUREMENT_BUYER (مسؤول مشتريات / مشتري), global RBAC assignment limited to the Wholesale/B2B business channel and authorized enterprise company/store scope; not SUPER_ADMIN.
- **Existing placement:** Reuse the canonical **ERP → Procurement** workbench at \`admin.erp.procurement.index\` with \`erp_procurement\` Sidebar projection. No new route/sidebar/menu is created. Domain=Procurement; entity=Supplier/Purchase Request/Purchase Order; goal=maker operations; workflow=Supplier → PR draft → Submit for independent approval; pattern=existing workbench page tabs; desktop/tablet/mobile=existing responsive workbench; AR RTL/EN LTR through existing localized markup; navigation type=DOMAIN_HUB/PAGE_TAB; permission=authoritative per-action RBAC, module and enterprise data scope; empty, validation and forbidden states remain under the existing controller.
- **Source-of-truth implementation:** \`config/job_roles.php\` + \`BuiltInJobRoleCatalog\` → active role permissions; \`TenantContextResolver\` + \`EnterpriseScopeAuthorizer\` → B2B company/store data scope; \`EnterpriseProcurementController\`, \`SupplierDirectory\`, \`PurchaseRequestManager\`, \`PurchaseOrderManager\` → real HTTP mutations and independent approvals. UI is only a projection of these decisions.

## Exact role permissions

Allowed after procurement module is enabled and the actor is active:

- \`procurement.suppliers.view/manage\`
- \`procurement.purchase_requests.view/manage\`
- \`procurement.purchase_orders.view/manage\`
- \`procurement.goods_receipts.view\`
- \`procurement.purchase_invoices.view\`
- \`procurement.supplier_statements.view\`

**Not implied:** \`procurement.purchase_requests.approve\`, \`procurement.purchase_orders.issue\`, \`procurement.goods_receipts.post\`, \`procurement.purchase_invoices.handoff\`, \`procurement.purchase_returns.post\`, \`finance.approve/post\`, \`orders.dispatch\`, security administration or Retail-store permissions. A creator/submitter cannot approve the same purchase request; existing independent maker/checker enforcement remains authoritative. Module-off and explicit user DENY must fail closed without removing historical role assignments.

## Executable acceptance evidence

\`backend/tests/Feature/V2ProcurementBuyerAuthorityTest.php\` verifies these actual paths without introducing new routes or test-only authorization:

1. Canonical seeded role permission set and localized Arabic/English name, description, responsibilities and required procurement module.
2. Disabled procurement fails closed; after test-only module certification/enablement, a real authorized buyer sees the existing Sidebar workbench and its rendered AR/EN, RTL/LTR \`requests\` tab; the \`returns\` tab and finance/security navigation remain unauthorized.
3. Real HTTP create supplier → create PR with persisted line → submit PR, verifying authoritative rows, maker attribution, submitted status and audit records; the same actor's real decision POST is denied, without status/approval mutation.
4. A forged Retail \`store_id\` is denied, security Dashboard is denied, global supplier View DENY suppresses the supplier tab, and an inactive buyer's HTTP access is refused.

Run exact-head focused Laravel tests plus complete Repository Policy, V2 Isolation Gate, backend Laravel/Pint/PHPStan/security/OpenAPI, AR/EN, and MySQL/Redis Fresh Install/Upgrade/Recovery Required CI before merge. **Status/links remain pending until observed on GitHub**; no false runtime/UAT claim.

## Cross-surface impact

| Surface | Result | Rationale |
|---|---|---|
| Laravel Backend / ERP Dashboard | Affected: acceptance test and evidence | Existing canonical role, workbench, mutations, audit, tenancy and language behavior tested directly |
| Customer Flutter | No application code/API contract change | No Customer REST endpoint, checkout, pricing or session behavior changed; only existing Admin ERP workbench exercised |
| Driver Flutter | No application code/API contract change | No Driver dispatch, wallet, delivery states or auth endpoint changed; B2C=DRIVER ONLY preserved |
| Van Flutter | No application code/API contract change | No Van assignment, visit, consignment, offline or token endpoint changed; B2B=VAN ONLY preserved |

No data migration, role grants, entitlement bypass, new strings, UI components or release artifacts. Historical production-account reconciliation is not inferred from synthetic tests. The owner has deferred manual UAT/staging/device/browser acceptance to the final V2 program gate (#2503/#2750); it is not misrepresented as run.

## Delivery constraints

Branch \`test/2689-v2-procurement-buyer-authority\`; exactly one PR to \`Foodex_V2\`. Update the Issue with PR ID, exact tested head SHA, required child-job run URLs, result and isolated merge SHA. Close #2689 only if full T122R9 source-scope acceptance is met by the existing implementation plus executable regression; otherwise retain OPEN with a precise handoff. No \`main\`, release tags, APK publishing, cPanel or production changes.
