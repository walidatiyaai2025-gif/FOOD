# V2 #2688 — Procurement Manager (T122R8) scoped authorization and maker/checker evidence

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, native Table 10 row 8 / T122R8. **Parent:** #2503. **Target:** `Foodex_V2` exclusively. No main integration, production, releases, tags, or app publication.

## Implementation and placement

This source role already exists in the canonical `backend/config/job_roles.php` under `PROCUREMENT_MANAGER`, is seeded by `CoreReferenceSeeder`/the existing `BuiltInJobRoleCatalog`, and has AR/EN translations in `backend/lang/{ar,en}/job_roles.php`. Reuse, do not recreate: it is a global role constrained to tenant business scope, requiring the `procurement` module, optionally consuming `inventory_wms` and `finance` contexts, and holding the canonical twenty procurement and reporting permissions. Optional modules **do not** become extra finance/treasury grants.

**Navigation decision:** Domain Procurement; entities suppliers, requests, RFQs, purchase orders, receipts, returns, invoices, supplier statements and reports; actor Procurement Manager; user goal lead the authorized B2B purchasing lifecycle. **Reuse** existing ERP Procurement workbench and its `erp_procurement` Sidebar entry under the business procurement/supply domain, `admin.erp.procurement.index` route and page-level tabs. No new screen, sidebar branch, route or standalone approval engine. Backend authorization remains `BuiltInJobRoleCatalog` + module lifecycle + platform entitlement + `OrganizationHierarchyService` / tenant/store scope + existing Procurement service action checks, and not any display/visibility preference. Disabled/unready module, missing entitlement, inactive role, user DENY and forged Retail store context fail closed.

## Explicit scope and separation

- Allowed: named `procurement.*` grants for supplier and request management, approval, PO management and issue, goods receipts, returns, purchase invoices, statements, and `reports.view/export` from the canonical built-in bundle. No wildcard rights or `SUPER_ADMIN` inheritance.
- Forbidden: `finance.manage/post/approve`, `security.approve`, `roles.manage`, `users.delete`, operational `orders.dispatch`, and Retail B2C workspace grants. Sales dispatch remains segregated.
- **Maker/checker:** This manager role may technically create and approve *different* requisitions, but the actual purchase-request service blocks self-approval by the creator/submitter. A separate authenticated manager must make an independently audited approval. Role permissions never waive transaction-level separation.
- **Company/channel/store:** Operational test uses the canonical Wholesale principal and a forged unrelated Retail store ID. Real data belonging to any foreign enterprise legal entity must be denied by the existing tenant authorization service. No production customer datasets were accessed.
- **Audit/recovery:** Approvals must persist `decided_by` and `enterprise.purchase_request.approved` audit provenance. A failed self-decision preserves the submitted row. Inactive/denied roles retain historical role assignments without granting access.

## Executable proof

`backend/tests/Feature/V2ProcurementManagerAuthorityTest.php` adds four additive feature tests:

1. Exact twenty-permission seeded role (not a UI copy), required/optional module list, manager level, bilingual name/description/responsibilities.
2. Fail-closed disabled Procurement module, then entitled healthy module + authorized Arabic RTL / English LTR real workbench GET, matching Sidebar, no Finance/Treasury/Security entries; Retail foreign store HTTP 403 and no finance/admin privilege.
3. Real supplier creation, purchase-request creation + submit, manager's own approval rejected with web validation error and unchanged status; independent second manager approves with real persistence and audit event.
4. Global per-user DENY suppresses real Supplier HTTP access, inactive account cannot access even if assignment history remains.

No production role/permission seeder changed. The tests exercise existing accessible shared UI and authorization rather than manufacturing a dummy role/page. Required responsive/keyboard/visual-device UAT remains a **V2 final gate** under #2503/#2750; no manual acceptance is claimed in this issue.

## Customer / Driver / Van impact matrix

| App | Impact | Proof / follow-up |
| --- | --- | --- |
| Customer | **Not affected** by additive Dashboard-focused tests/docs; no Customer authentication/API/pricing/order contract change | Existing wholesale customer scope preserved; no mobile issue needed unless a future shared contract changes |
| Driver | **Not affected**, no Flutter/dispatch/wallet or shared delivery API change | B2C=DRIVER ONLY unaffected; never grant dispatch from Procurement role |
| Van | **Not affected**, no Van assignment, visit, consignment, offline or shared endpoint change | B2B=VAN ONLY unaffected; never grant a Van credential from Procurement Manager |

## CI and delivery checklist

Run exact-head focused Laravel test, PHP syntax/Pint/PHPStan, Repository Policy, V2 Isolation Gate, Localization AR/EN, complete Required CI (including Laravel, MySQL/Redis installation/upgrade/recovery where mapped). Verify `base.ref === Foodex_V2` and final head SHA before merge. If tests fail, fix on **the same branch/PR**, do not alter or overlap the open Procurement Controller owner #2845 / PR #3206. Link final run IDs and merge commit to #2688, then close only when full implementation acceptance is proven. Manual UX/UAT is `DEFERRED_TO_V2_FINAL` per master #2503. No direct push to Foodex_V2 and no production action.
