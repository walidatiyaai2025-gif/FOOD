# FOODEX V2 #2627 — P297 Bank Reconciliation / التسويات البنكية

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph P297, parent #2503. **Integration:** `Foodex_V2` only.

## Canonical UI placement and workflow

- **Domain / entity:** Finance & Treasury → bank statements, bank accounts, reconciliation drafts, posted receipt/payment matches and checker-controlled closure.
- **Actors:** authenticated Retail finance operator with store-bound `finance.view` and `treasury.reconciliation.read` entitlement for listing; makers require `finance.manage` plus `treasury.reconciliation.manage`; independent closers require `finance.manage` plus `treasury.reconciliation.approve` and must not be the record's `requested_by` maker.
- **User goal / frequency / density:** frequent reconciliation review and exception resolution in a bounded, paginated dense business table; specialized form actions for statement creation, match allocation and close.
- **Route/navigation owner:** existing Finance business Sidebar `erp_treasury` → `admin.erp.treasury.index?store_id=<authorized>&tab=reconciliation`. Horizontal page tab is the canonical function-level entry and reconciliations are not a new top-level Sidebar page.
- **Placement:** **Reuse + minimal extension** of the live Treasury workbench. The existing `EnterpriseTreasuryController`, `TreasuryReconciliationService`, route, localizations and responsive Blade renderer are authoritative. No duplicate database ledger, report, Sidebar entry or REST endpoint.
- **Pattern & context:** existing page-level tabs and selected-store form, filtered account select (master data), bounded reconciliation table, form create/match/close. Same responsive classes, off-canvas admin navigation, AR/RTL and EN/LTR. No new design tokens, CSS, or raw ID inputs.
- **Backend authority:** `TreasuryReconciliationService` uses `EnterpriseScopeAuthorizer` + store-scoped `ModuleEntitlementResolver` and filters organization/company/legal entity/store. Draft creation is idempotent; posting/matching/closing retains established audited maker/checker and balanced statement rules.
- **Current defect remediated:** `canManage` was only a broad `finance.manage` UI flag and exposed reconciliation create/match/close actions to users whose actual reconciliation capability was missing, or to makers disallowed from closing their own draft. The existing Blade now uses separately projected `canManageReconciliation` / `canApproveReconciliation` gates from exactly the same store entitlement as the service; closure also hides for `requested_by` maker. No server authorization relaxed or bypassed.

## Authorization and regression contract

`backend/tests/Feature/V2BankReconciliationSidebarContractTest.php` covers real Laravel authenticated Dashboard HTTP and DB state:

1. Disabled Treasury module denies the direct reconciliation route.
2. Store-bound `finance.view` + reconciliation read capability permits the tab but **not** create/match actions when `treasury.reconciliation.manage` is absent. Direct POST 403, no new finance row.
3. A fully authorized maker can create a reconciliation draft through the existing Dashboard POST; identical idempotency-key replay writes one record only.
4. The maker can view the draft in Arabic/RTL and English/LTR but cannot see the close action; an independent, equally authorized same-store checker can see it. Maker direct POST rejects with validation and does not close the record.
5. Foreign B2C store context returns 403, and an explicit effective store-scoped `finance.view` deny blocks even a previously entitled route.

Tests use a fresh disposable database, test-only module certification, native roles and canonical entitlement manager. These settings do not modify production module readiness. Existing schema/ledger and mobile routes remain untouched.

## Cross-surface impact assessment

| Surface | Classification / proof |
| --- | --- |
| Laravel Backend / Finance | Impacted Dashboard authorization projection only; underlying Treasury service, payment posting, MySQL schema, audit and reconciliation amount semantics are unchanged; direct negative POST/GET tests prove server enforcement |
| Dashboard | Impacted existing reconciliation tab create/match/close visibility only; financial master-data account selector, tabs, responsive shared shell, AR/EN labels, keyboard-native buttons and links preserved |
| Customer Flutter | Not affected: no Customer identity, credit, order, invoice, checkout or API contract change |
| Driver Flutter | Not affected: no B2C Driver assignment, collection, wallet, proof, notification or API contract change |
| Van Flutter | Not affected: no B2B Van dispatch, route, visit, remittance, stock or offline API contract change |

**Channel contract:** B2B fulfillment remains Van only; B2C remains Driver only. A Retail finance user never gains wholesale/customer/driver/van control through the Treasury Sidebar.

## Acceptance and CI

- Required exact-head CI: Repository Policy, FOODEX V2 Isolation Gate, Laravel feature/full tests, PHP syntax/Pint/static analysis/security/OpenAPI, AR/EN localization, real MySQL/Redis install/upgrade/recovery acceptance and `required-ci-gate`.
- UI audit: Existing route and canonical shell reused; no new page or Sidebar. Existing page supplies viewport, navigation, CSS and form controls. New tests enforce button visibility and data/role boundaries in live Laravel HTML; real device/browser UAT intentionally deferred by owner decision in #2503 to V2 final acceptance #2750.
- Merge only into `Foodex_V2` after exact-head checks succeed; no main, release tags, cPanel/production or external staging effects.
- Owner closure rule (#2503, 2026-11-10): code-complete leaves with exact-head green CI can close `completed` with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. If any automated/source-scope requirement remains unproven, keep this leaf OPEN and document blockers.
