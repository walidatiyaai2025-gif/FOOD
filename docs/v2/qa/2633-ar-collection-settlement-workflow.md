# V2 #2633 / P303 — AR settlement allocations within canonical Finance

## Placement authority

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P303, parent #2503.
- Domain: Finance / Treasury; entity: posted Enterprise AR document and its allocation; actor: entitled Finance AR maker and independent Finance poster.
- User goal: review and settle a selected posted receivable without creating a second ledger or mistaking cost allocations for receipts.
- Workflow type: exact-record context action, potentially daily, finance-sensitive; data density is medium with server-side pagination.
- Canonical route and renderer: `admin.erp.finance.index?tab=receivables` in `FinanceWorkbenchController` and `finance-workbench.blade.php`. **Reuse → Extend** (not new screen/sidebar). Parent existing ERP Finance workbench; navigation_type `CONTEXT_ACTION`. Row ellipsis opens exact-document settlement details.
- Shared UI: FOODEX 44px row ellipsis and portal, operational modal, finance table and 25-row settlement paginator with preserved query, AR/EN paired locale, RTL/LTR and responsive dashboard shell. The document list does not unboundedly load all allocations.
- Authoritative actions: `POST admin.erp.finance.receivables.settlements.store` → `AccountsReceivableService::createAllocation` (draft only). `POST admin.erp.finance.receivables.settlements.post` → `AccountsReceivableService::postAllocation(..., requiresVerifiedCollectionSource: true)` now **fails closed** after permission/SoD/tenant checks because no authenticated receipt source is persisted. The route remains under Admin ERP middleware and rate limiting. Canonical non-P303 AR service callers retain their previous posting contract; they must not be mislabeled as collection-backed settlement.
- Retail tenant isolation: canonical AR service now constrains document pagination, aging, create/draft/post/cancel/allocation paths to `context.storeId` for retail, even when multiple stores share a legal entity. The new Admin AR detail/POST endpoints also reject foreign-store IDs with 404 before mutation; this prevents direct-route bypass and preexisting legal-entity-wide listing of another store's AR. Wholesale retains its authorized enterprise legal-entity semantics.
- Permission and SoD: `finance.view` + `finance.ar.read` for browsing; `finance.manage` + `finance.ar.manage` for draft creation; `finance.post` + `finance.ar.post` for independent posting. Creator cannot post own allocation. Unentitled or module-disabled operations fail closed; source document must be posted; scope includes legal-entity identifiers; posting enforces posted outstanding maximum and Finance/GL approval. Account picker lists only active posting accounts of the selected legal entity. Business users do not type internal IDs.
- Audit: Draft writes create `enterprise.finance.ar_allocation_created`; unverified P303 post attempts never create `enterprise.finance.ar_allocation_posted` or a Finance journal. Legitimate pre-existing canonical AR posting retains its separate history. Retry uses stable form idempotency key; new business request generates new key.
- Failure and recovery: invalid foreign document returns 404; conflicting idempotency key or over-allocation returns validation error; failed posting leaves draft for authorized review. Database transaction and canonical journal state machine are unchanged. No manual rollback of posted journal is implied.
- UI states: selected document, no settlements, draft/unverified receipt, previously posted linked journal, disabled create when no posting account or no manage permission, missing entitlement 403. An AR/EN source-verification warning replaces the unsafe P303 Post form on a draft. A crafted direct POST is denied with `collection_source` validation and no financial mutation; page/list/pagination and language remain unchanged.
- No backend/mobile API contract, app navigation or fulfillment behavior changed.

## Explicit three-app impact decision

| App | Impact | Evidence |
| --- | --- | --- |
| Customer | No app or public API changes; customer invoice/ledger APIs untouched | Only backend Admin Finance routes / view / language / tests changed |
| Driver | No app/API changes; B2C DRIVER ONLY and Driver collection contracts untouched | No Driver/dispatch/collection table changes |
| Van | No app/API changes; B2B VAN ONLY and Van collection/remittance workflows untouched | No Van/dispatch/visit/stock table changes |

## Reproducible tests and release gates

`backend/tests/Feature/V2ReceivableSettlementWorkbenchTest.php`: seeded, legal-entity entitled Finance fixture; authorized AR/EN exact-record route and draft modal; draft create and retry idempotency; maker cannot post; independently authorized poster also cannot post a draft without verified receipt provenance; both-language warning and absence of unsafe Post control; forged direct HTTP attempts leave the draft and journal count unchanged; cross-store direct GET/POST denied including sibling stores sharing one legal entity; canonical AR list excludes sibling documents; over-allocation rejected without financial mutation. Reuse broader `EnterpriseAccountsReceivableFoundationTest` for GL approval, account and entitlement behavior and `FinanceWorkbenchTest` for general tab/projection/AR-EN/module-disabled cases.

Run: `php artisan test --filter=V2ReceivableSettlementWorkbenchTest`, `php artisan test --filter=EnterpriseAccountsReceivableFoundationTest`, plus full exact-head Repository Policy, V2 Isolation, localization and required-ci-gate (including MySQL/Redis upgrade and recovery). Browser/device tests for desktop/320-430px mobile, keyboard, RTL/LTR remain independent final evidence; no screenshot workflow is claimed.

## Source provenance checklist (P303, 2026-11-13)

The following are **unimplemented acceptance prerequisites**, not evidence of a verified settlement:

1. Identify an immutable authoritative receipt/collection transaction using a typed source key, **not** the operator's arbitrary `reference` text or an unchecked transaction ID.
2. Independently match the receipt's originating store/channel, customer or order, currency, posted status, and valid collection account to the posted AR document. A shared legal entity must never authorize cross-store Retail matching.
3. Enforce source amount availability across **all** allocations for that receipt (including concurrent requests), with database locking, idempotency and an auditable consumption/reversal strategy. Existing `enterprise_ar_allocations` has a nullable free-text `reference` but **no receipt foreign key or source uniqueness**.
4. Permit maker/checker posting only after the server verifies the above source in the same financial transaction; derive evidence for journal/audit links and safe retry/recovery. Never represent the existing `finance.ar.post` permission as sufficient receipt provenance.
5. Provide an authorized, scoped business-facing receipt lookup in Arabic/English (not raw IDs); verify cross-store, revoked/disabled-module, duplicate receipt, unposted receipt, overcollection, no-write-on-deny and app contract parity under MySQL CI.

**Interim scope evidence:** `V2ReceivableSettlementWorkbenchTest` asserts AR/EN unverified-reference and pending-verification warnings, no Post control, and a direct POST denial with zero new journal or posting audit. `AccountsReceivableService::postAllocation` uses a source-required gate only for the Admin P303 route; no receipt source has been invented. This is **fail-closed protection**, not an implementation or validation of receipt provenance. Keep PR #3309 DRAFT and Issue #2633 OPEN until the above transaction-level acceptance is implemented and exact-head Required CI is green; this work does not waive unrelated #3160 Enterprise Performance baseline verification.

## Source acceptance NOT completed by this PR

This is an **AR allocation workflow** against a posted receivable. An operator-typed `reference` does **not** cryptographically or referentially tie the allocation to an authoritative collected receipt, Van collection, Driver collection, bank statement or Treasury reconciliation. This PR does not wire independent `collection_transactions` / receipt IDs to Enterprise AR allocation and must not be advertised as complete P303 collection-linked settlement. Exact proof of collection source, allocation↔receipt uniqueness, over-collection controls across source ledgers, multi-store financial ownership and reconciliation/policy acceptance must be designed and tested before closing #2633, without duplicating P297 #2627 bank reconciliation or P288 aging #2618.

Maintain #2633 OPEN pending those requirements and final V2 audit. No main PR, production, tags or release artifacts authorized.
