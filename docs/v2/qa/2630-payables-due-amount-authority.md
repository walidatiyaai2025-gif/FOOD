# FOODEX V2 #2630 — P300 Payables Due / المستحق السداد

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P300 (Finance & Treasury). Parent #2503. Integration Foodex_V2 only, never main/release/tags/cPanel/production.

## Canonical workflow and placement

- Domain/entity: Finance & Treasury → Accounts Payable → posted supplier AP document, posted settlement allocation, due date and outstanding balance.
- Business actor: active scoped Finance/AP reader with finance.view on an authorized selected Retail store, ENABLED and certified Finance module, legal-entity finance.ap.read. Creation, posting and allocation have stronger permissions, entitlements and separate maker/checker; display does not grant payments or approvals.
- User goal: see what is STILL DUE for payment, with supplier, due date, net balance and aging bucket. Fully paid documents remain in posted audit/document list but not in outstanding aging list; drafts are not payable yet.
- Placement: REUSE Finance → existing receivables business group (P285) → Payables tab at canonical admin.erp.finance.index?store_id=authorized&tab=payables, existing PAGE_TAB of the responsive Finance Workbench. Authorized store is selected from real master data, not raw ID entry. Navigation owner existing Finance group, no independent sidebar or permission policy.
- Backend source: FinanceWorkbenchController::payableData delegates to AccountsPayableService::aging. Production query limits to posted docs by organization/company/legal entity AND store, outstanding amount (gross minus posted allocations) strictly positive; sorts by due date and bounds results (100 max). No new payment route or unbounded report.
- Different scopes: P300 does NOT implement P296 bank payout, P297 bank reconciliation, P298 supplier settlements or P299 accrual recognition.
- Existing AR RTL / EN LTR responsive Finance tabs, localized aging statuses and payable amounts remain unchanged. Final real-browser mobile/web/a11y UAT deferred to V2 master #2503/#2750, not claimed executed.

## Executable acceptance, real AP due after posting and settlement

New isolated Feature test: backend/tests/Feature/V2PayablesDueAmountAuthorityTest.php

1. Two distinct authorized Finance users (maker and checker) construct real finance accounts/fiscal period and AP drafts using production services; checker independently posts two AP documents to the canonical GL.
2. First payable has 100.000 KWD amount; 40.000 KWD POSTED allocation leaves 60.000 KWD due. Second 70.000 payable gets fully allocated/posted; due report MUST exclude this paid-off document, while preserving it in posted documents for audit.
3. Real protected HTTP Payables page filtered by supplier yields that one actual due item and 1–30 day overdue bucket as of fixed business date 2027-03-01. No direct test DB writes to bypass posting or calculate a made-up amount. Assert four real GL journals from posted documents + allocations.
4. Negative checks: foreign Retail store HTTP 404; missing AP-specific entitlement HTTP 403 even if foundation read remains, roleless user 403, global explicit finance.view DENY 403, uncertified module HTTP 403.
5. P287 already owns ordinary AP tab and localized role guards (V2AccountsPayablePageAuthorityTest). Existing EnterpriseAccountsPayableFoundationTest owns maker/checker and overpayment/regression/finance posting rules. This P300 test adds net-due + payable aging HTML evidence; no source feature duplication.

## Cross-app impact and safe file fence

Backend: NEW test only, no runtime API/controllers/migrations/auth/finance ledger edits. Dashboard: existing localized Finance Payables page reused, no new Blade/JS/Sidebar/routes/permission. Customer: NOT AFFECTED (no API/cart/invoice). Driver: NOT AFFECTED (B2C DRIVER ONLY, no wallet/dispatch). Van: NOT AFFECTED (B2B VAN ONLY, no visit/stock/route). Only synthetic test database data. Preserve any existing peer-owned Finance controller/Navigation source; current P280 PR #3276 remains independently owned.

## CI gate and closure

One issue #2630, one branch test/2630-v2-payables-due-amount-authority, one PR BASE Foodex_V2 ONLY. Before integration require exact-final-head Repository Policy, FOODEX V2 Isolation, terminal Required CI, real Laravel/PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN localization and MySQL/Redis fresh-install/upgrade/recovery all SUCCESS. Never bypass failing checks or claim production verification. Close leaf only on proven merge and full source acceptance. MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL in #2503/#2750.
