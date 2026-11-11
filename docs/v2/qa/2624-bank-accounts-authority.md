# V2 P294 — Banks / Treasury Master authorized destination

Issue: #2624. Branch: `test/2624-v2-bank-accounts-authority`. **Merge target: Foodex_V2 ONLY.**

## Reuse / placement
- Domain: Finance & Treasury; entity: bank account master data; role: authorized B2B finance operators and store-scoped Retail Finance users.
- Workflow: inspect or manage bank accounts before treasury receipts, cheques or reconciliation. Existing canonical route `admin.treasury.master.index?tab=bank_accounts`; existing Finance Sidebar entry `treasury_master` (platform) and Treasury Workbench master-data link (authorized Retail workflows).
- Placement: **Reuse → existing horizontal PAGE_TAB** within Treasury Master, not an independent new page or Sidebar item.
- Permissions: `finance.view` for GET, `finance.manage` for bank account create/edit; `OperationalTenantScope::allowedStoreIds/assertStore` is server authority. Individual STORE DENY takes precedence; no UI-only grants. Existing module readiness is checked independently by ERP Treasury Workbench; bank master data remains a finance-scoped reference workspace, not proof that ERP transaction processing is enabled.
- States: AR/RTL and EN/LTR tab labels, mobile responsive Treasury Master and existing empty/data/form states; account reference is encrypted at rest and only last four digits displayed in table. No raw account numbers in audit evidence.
- Fields: authoritative Store lookup; state/visibility enums; bank reference is a legitimate restricted bank-account input stored encrypted, not an internal ID typed by a business user.

## Acceptance / safety
- `backend/tests/Feature/V2BankAccountsSidebarAuthorityTest.php`: real authorized B2B Sidebar → tab HTTP, AR/EN labels, masked sensitive bank ref, B2C store A/B isolation, forged cross-store GET/edit/POST negative cases, view-only user cannot mutate, explicit STORE DENY, and roleless 403.
- Canonical controller/services and existing bank master view are reused unchanged. These tests are additive to #2726 Treasury Sidebar migration and do not overlap #2627 reconciliation ownership. No finance operation, ledger write, cheque/reconciliation data, production bank account or runtime store modified.
- Customer: NOT AFFECTED; no mobile API/app change. Driver: NOT AFFECTED; no driver delivery or payment contract change. Van: NOT AFFECTED; no Van cash/collection API or app change.
- Automated GitHub exact-head tests/AR-EN/security gate required before merge. Manual UAT deferred to V2 final master #2503. No main, tag, release or production deployment.
