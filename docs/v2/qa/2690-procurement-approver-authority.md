# V2 #2690 — Procurement Approver real maker/checker authority

- Source: FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), T122R10; parent #2503.
- Role: \`PROCUREMENT_APPROVER\` / معتمد المشتريات; global role, B2B tenant/channel, mandatory enabled and operationally certified Procurement module.
- Decision: **Reuse** the canonical ERP Procurement workbench, existing \`admin.erp.procurement.index\` (requests, orders, supplier/reference/report tabs), without adding a new route or Sidebar entry. Business domain: Procurement; entity: Purchase Request/Purchase Order; role: Independent Checker; user goal: approve/reject submitted request and issue an order that was drafted by a separate maker; workflow: Buyer creates supplier and PR → buyer submits → approver reviews and decides → buyer drafts PO → approver issues PO. Frequency: recurring controlled business operations; density: existing grid/tabs; parent: ERP Procurement; placement: business workbench with existing tabs/actions; pattern: existing domain workbench; navigation type: DOMAIN_HUB/PAGE_TAB; RTL/LTR and desktop/tablet/mobile: unchanged existing shared, responsive, bilingual markup. Back/path: workbench actions redirect to selected request/order in same tab; authority comes from exact server-side role/scope, not a visible link.

## Exact privileges and separation of duties

Allowed: \`procurement.suppliers.view\`, \`procurement.purchase_requests.view\`, \`procurement.purchase_requests.approve\`, \`procurement.purchase_orders.view\`, \`procurement.purchase_orders.issue\`, \`procurement.purchase_invoices.view\`, \`procurement.supplier_statements.view\`, \`reports.view\`.

Denied: supplier management, purchase-request create/update/submit, purchase-order draft/create/update, receipt create/post, invoice handoff, returns post, finance approval/post, store management, order dispatch, security or role administration. Authorization is resolved through BuiltInJobRoleCatalog, EnterpriseScopeAuthorizer, TenantContextResolver, module readiness/entitlement and per-user DENY. \`PurchaseRequestManager\` prevents creator/submitter self-approval even if a combination of roles would otherwise grant it. Existing audit logs record decisions and issuance. Existing disabled/inactive accounts and forged Retail scopes fail closed.

## Executable acceptance test

\`backend/tests/Feature/V2ProcurementApproverAuthorityTest.php\` is issue-scoped and exercises real Laravel HTTP:

1. Seeded role code/scope, module prerequisites, exact grants and Arabic/English labels.
2. Module disabled route denial, explicit platform entitlement after module enabled + test-only exact-version attestation, discoverable procurement Sidebar and AR RTL / EN LTR requests tab; unauthorized receipts tab and Finance/Treasury/Security links absent.
3. Separate built-in \`PROCUREMENT_BUYER\` and \`PROCUREMENT_APPROVER\` actors. Checker is rejected for supplier/PR/PO draft creation. Buyer creates a real supplier and submitted PR, but cannot approve. Checker approves and leaves an audit event. Buyer drafts an order but cannot issue. Checker issues it with server-side persisted status and audit event.
4. Forged B2C Retail store selector and administrative/security surface rejected; explicit global approval DENY defeats role grant; inactive checker loses the HTTP route.

No production PHP or database schema changes, no new permissions, no privileged bypass and no user data migrations. Frontend rendering uses unchanged canonical ERP workbench. CI proof is pending until exact-head Repository Policy, V2 Isolation, backend PHP syntax/Pint/PHPStan/security/OpenAPI, localized AR/EN, full Laravel, MySQL/Redis clean setup/upgrade/recovery and terminal \`required-ci-gate\` succeed. Owner deferred manual UAT/staging/browser/device acceptance; it is not claimed.

## Customer / Driver / Van parity impact matrix

| Component | Changed | Contract and justification |
|---|---:|---|
| Laravel Backend | Issue-scoped tests only | Exercises existing ERP supplier/request/order endpoints, genuine DB/audit and module authorization |
| Admin Dashboard | No runtime code changes | Exact existing Procurement workbench and Sidebar reused with AR/EN and responsive structure |
| Customer Flutter | None | No customer catalog/checkout/request/notification API change |
| Driver Flutter | None | No B2C delivery assignment, status, wallet or notification contract change; B2C=DRIVER ONLY |
| Van Flutter | None | No B2B Van trip, assignment, proof, collection or offline sync contract change; B2B=VAN ONLY |

PR from \`test/2690-v2-procurement-approver-authority\` into **Foodex_V2 only**. Owner: ChatGPT GitHub Connector worker, Issue #2690. Do not merge into \`main\`, deploy to staging/production, publish releases/tags or close the Issue without all exact-head Required CI jobs and the source-level DoD verified.
