# V2 #2843 / P109 — ERP operational lifecycle evidence (partial)

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P109. **Parent:** #2503. **Only integration target:** `Foodex_V2`. No production or `main` promotion.

## Canonical implementation inspected

- `GeneralLedgerService::createDraft` persists balanced draft journal and immutable audited request metadata, while `post` checks fiscal-period openness, permissions, finance module entitlement/readiness, independent poster and approved Finance checker workflow before writing immutable ledger entries.
- `FinanceApprovalService::requestApproval / approve / assertPostingAuthorized` uses the existing WorkflowEngine and scoped maker/checker approval limits. It is not an independent UI-only permission decision.
- `GeneralLedgerService::paginateJournals / paginateLedger` provide scoped, paginated reads; Finance Workbench Journals is reachable through the authorized `admin.erp.finance.index` route and locale-specific layout.
- Existing focused proofs: `EnterpriseGeneralLedgerFoundationTest`, `V2FinanceTreasuryDutySeparationTest`, `V2FinanceJournalsRouteAuthorityTest`, `FinanceWorkbenchTest`, `EnterpriseWorkflowEngineTest`. Avoid duplicating their production implementations.

## New isolated test proof

`backend/tests/Feature/V2ErpPostingLifecycleContractTest.php` checks one real balanced journal through four distinct store-scoped actors: Maker, Checker, Poster and Reader. It exercises a premature posting denial, maker self-approval denial, checker self-posting denial even when checker holds `finance.post`, independent approval, idempotent posting, two (and only two) ledger entries, scoped journal/ledger reporting and three audit events. It checks the canonical Dashboard Journals view in Arabic/RTL and English/LTR, a foreign store 404, and a disabled Finance module 403. All data is synthetic test-only; no live account/source material is logged.

These tests are only **proposed** until the exact branch-head backend/AR-EN/security and Required CI gates finish successfully. Do not infer operational ERP certification from the test's presence.

## Source requirements not yet certified — do not close Issue

1. **Full reversal path** is missing from the inspected `GeneralLedgerService` interface: it has `createDraft`, `post`, read pagination but no journal `reverse` action. Do **not** update/delete a posted entry or call a migration rollback a financial reversal. A correct solution needs approved, authorized compensating journal semantics, original/reversal links, period rules, idempotency, audit and separate checker/poster (SoD), report effects and failure/retry tests.
2. **Review/reject, approval, posting and reversal** need one end-to-end HTTP/Dashboard action and role+route authorization matrix. Existing service-level assertions alone do not establish navigable ERP UX or acceptance of every source business process.
3. **Reports** must show reconciled posted/reversed totals and complete eligible records, not just a bounded 100-row list; reconcile with Finance, Accounts Receivable and Accounts Payable subdomains.
4. **Scope matrix** requires B2B legal-entity/company/warehouse and B2C store-crossing, multi-role explicit DENY, permission granularity, module health/readiness, channel and inactive-account tests. Do not broaden access while Finance/Treasury SoD #2851 and ERP workflow prerequisite #2858 are under separate ownership.
5. **Operational readiness** requires real business-document lifecycle evidence and independent staging/browser/accessibility acceptance, not synthetic certification or activation of an unready module.

## App impact classification

| Surface | Current proposed delta | Follow-up requirement |
| --- | --- | --- |
| Backend / ERP | New additive Laravel feature test only, no source/controller/API change | Complete reversal, real HTTP transitions and full data/SoD checks |
| Dashboard | Existing authorized Finance Journals route exercised, no UI source change | Real operator review/approve/reverse/report workflows, AR/EN, keyboard/mobile accessibility |
| Customer | No Customer API/schema/Flutter delta in this test | If actual financial posting/credit/invoice API changes, link impacted V2 Customer issue and run corresponding tests |
| Driver | No dispatch, wallet/settlement or Driver API/Flutter delta | Any real settlement impact requires Driver issue, API and offline regression |
| Van | No Van consignment/settlement or Van API/Flutter delta | Any real posting or compensation change requires Van issue, offline/idempotency tests; B2B=Van only invariant preserved |

**Status:** Partial automated proof only. Continue in the **same** #2843 branch and PR; do not merge a partial Draft PR or close #2843 until the complete source lifecycle, exact-head Required CI and impacted application contracts are satisfied. Human/browser/staging checks may be deferred to final V2 under #2503, but missing source behavior is not deferred. No `main`, release, tag or production change is authorized.
