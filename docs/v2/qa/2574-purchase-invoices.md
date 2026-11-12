# V2 P244 — Purchase invoices / فواتير الشراء

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P244. Issue #2574 under V2 master #2503. Integration exclusively into `Foodex_V2`; no `main`, production deployment, tags, releases, or APK publication.

## Canonical placement and route authority

- **Domain:** Procurement & Inventory / supplier purchase invoices. **Entity:** `enterprise_purchase_invoices` and its posted-GRN lines. **Role:** authorized procurement actor bound to the operative company/store context.
- **User goal:** prepare supplier invoices from posted goods receipts, inspect the exact invoice, hand off to Finance after three-way matching, and cancel drafts with audit.
- **Placement:** REUSE the existing Procurement Workbench at `admin.erp.procurement.index?tab=invoices`; navigation owner is existing ERP Procurement business entry, not a new Sidebar group or route. The invoice detail uses an exact-record `invoice` query value on the same authorized tab. Navigation type: `PAGE_TAB` via `ADMIN` domain workspace. No new UI or master data fields.
- **Server authority:** `EnterpriseProcurementController` and `PurchaseInvoiceHandoffManager` with `procurement.purchase_invoices.view`, `.manage`, `.handoff` and `EnterpriseScopeAuthorizer`; module lifecycle/readiness must authorize before a supplier invoice can be queried or changed. Supplier/receipt and invoice lookups are store/company scoped. A hidden tab never authorizes direct URL access.
- **Accounting controls:** only posted goods receipts source drafts; repeated supplier references are rejected; issued and returned quantities bound invoiceable units; three-way match review gates Finance handoff; successful handoff is idempotent via outbox, and cannot be silently cancelled.
- **Failure handling:** ten existing invoice-specific `ValidationException` messages are now sourced from `enterprise_procurement.errors.invoice_*` for AR/EN. Dynamic receipt, line, invoice, localized action/status and remaining quantity stay present without exposing implementation details. No exception bypass or permission change.

## Evidence and functional scope

The isolated regression `EnterprisePurchaseInvoiceHandoffFoundationTest::test_p244_invoice_rejections_remain_localized_and_do_not_change_financial_state` exercises a real posted GRN/invoice/handoff across Arabic and English. It checks duplicate supplier reference, net invoiceable quantity cap, and prohibition on cancelling a handed-off invoice; rejects must differ by locale, keep one invoice/outbox event, and never write a cancel audit row. Existing foundation tests continue covering cross-store reads, disabled module, maker/checker 3-way matching, returned quantities, immutable handoff, and retry safety.

**Customer app:** Not affected — no storefront/Customer API or Flutter changes. **Driver app:** Not affected — no B2C dispatch/wallet contract changes. **Van app:** Not affected — no B2B route/visit/consignment API change. B2B→Van and B2C→Driver isolation remains unchanged.

## Verification / acceptance remaining

Required exact-head verification is Repository Policy + FOODEX V2 Isolation + FOODEX Required CI terminal gate (PHP syntax/Pint/PHPStan/security, complete Laravel suite, MySQL/Redis fresh install/upgrade/recovery and AR/EN). AR/EN translations must be exercised through the regression. Test assertions do not replace independent real-runtime browser responsive, screen-reader, RTL/LTR and end-to-end role/store/disabled-module acceptance. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2750; keep #2574 OPEN if source-to-page acceptance remains incomplete. No claims of real-device UI tests or actual production deployment.
