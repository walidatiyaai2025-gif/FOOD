# V2 #2852 / P128 — Supplier contract / settlement / payment SoD

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P128, parent #2503. Delivery only to `Foodex_V2`; **not approved for production**.

## Authority and placement

| Stage | Canonical service / action | Existing or new server guard |
|---|---|---|
| Supplier contract author | `ProcurementCompletionManager::createContract` | `procurement.purchase_orders.manage`, store-scoped supplier and enterprise context, audited creator |
| Supplier contract activation | `ProcurementCompletionManager::activateContract` via `admin.erp.procurement.contracts.activate` | **New:** draft maker cannot activate the same contract; a different authorized actor, same permitted store and procurement scope, activates inside row-locked transaction and audit event |
| Accounts payable document & settlement | `AccountsPayableService::createDraft`, `post`, `createAllocation`, `postAllocation` | Existing: maker cannot post their AP document/allocation; finance entitlement, scoped operation, journal and approval context |
| Treasury payment request & release | `TreasuryPaymentService::createDraft` and `post` | Existing: payment requester cannot approve/post own payment; scoped entitlement, workflow approval and audit |

**Critical distinction:** This implementation enforces different *people on the same protected record*, not simply different Sidebar labels. It does not claim that every role with both configuration permissions is automatically segregation-compliant, or that a supplier contract automatically creates a payable or releases money.

## Changes and evidence

- `backend/app/Enterprise/Procurement/ProcurementCompletionManager.php`: contract activation retains current company/store/permission checks; uses a transaction plus existing row lock; rejects `created_by === actor.id` for draft state before activation; preserves existing idempotent active-state check and audit.
- `backend/tests/Feature/EnterpriseProcurementCompletionTest.php`: maker's **real HTTP POST** is rejected with `approver` session error in Arabic and English; draft remains unchanged and a second store-authorized actor activates. Existing contract ceiling, committed/released value, cross-store private-document and PO issue behavior remain tested with the checker.
- `backend/lang/ar/enterprise_procurement.php` and `backend/lang/en/enterprise_procurement.php`: localized, actionable denial rather than English literal fallback.

No new route, form, Sidebar entry, generic access grant or migration. Existing Procurement tab is the canonical UI placement (Reuse/Extend). The role, store, supplier, entitlement and readiness authorities remain in existing services. There are no new inputs or technical IDs demanded from users.

## Test matrix / acceptance

- Positive: separately authorized actor activates creator's draft, `activated_by` and audit persist.
- Negative: maker's direct HTTP POST in both `ar` and `en` is denied before status change; no self-approval even if maker holds broad permissions.
- Existing regressions: foreign store document denied, same-supplier contract linkage and contract value/quantity ceiling; AP and Treasury retain distinct requester/poster checks.
- CI: Laravel PHP syntax, Pint, PHPStan, localization, full tests, MySQL/Redis deployment acceptance and exact-head required gate. Link workflow IDs and final SHA in Issue/PR after the actual runs.
- Security: never introduce cross-store privilege from Sidebar; verify 403/404 where appropriate, retain maker/checker audit and financial history.
- AR/EN: localized error. RTL/LTR and mobile web layout are unchanged, require independent browser acceptance if UI-level defects are found.

## Backend / Dashboard / mobile impact matrix

| Surface | Assessment |
|---|---|
| Backend / API / database | Procurement contract activation service behavior changes for all callers; existing route and response contract preserved, no schema change |
| Dashboard | Existing `/admin/erp/procurement` contract activation action now rejects self-approval with bilingual validation error. No new screen; verify final error placement in isolated browser |
| Customer app | No direct customer-facing procurement contract route, token or Flutter source changes. No new mobile issue absent API effect |
| Driver app | No Driver fulfillment/settlement interface changed; B2C remains Driver only. |
| Van app | No Van sales/dispatch interface changed; B2B remains Van only. |

## Remaining production/owner acceptance — Issue stays OPEN

Verify production-representative supplier contract creator / approver delegations, actual AP allocation and payment release SoD end-to-end with separate actors and legal entity + store scope, Enterprise module readiness, finance history, UI error a11y, audit trace and browser RTL/LTR on isolated staging. A passing test-only merge is **not** payment or financial release certification.

Never merge to `main`, create release tags, publish APKs or deploy to production without separate owner authorization.
