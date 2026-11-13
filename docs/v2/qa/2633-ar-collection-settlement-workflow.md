# V2 #2633 / P303 — AR settlement allocations within canonical Finance

## Placement authority

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P303, parent #2503.
- Domain: Finance / Treasury; entity: posted Enterprise AR document and its allocation; actor: entitled Finance AR maker and independent Finance poster.
- User goal: review and settle a selected posted receivable without creating a second ledger or mistaking cost allocations for receipts.
- Workflow type: exact-record context action, potentially daily, finance-sensitive; data density is medium with server-side pagination.
- Canonical route and renderer: `admin.erp.finance.index?tab=receivables` in `FinanceWorkbenchController` and `finance-workbench.blade.php`. **Reuse → Extend** (not new screen/sidebar). Parent existing ERP Finance workbench; navigation_type `CONTEXT_ACTION`. Row ellipsis opens exact-document settlement details.
- Shared UI: FOODEX 44px row ellipsis and portal, operational modal, finance table and 25-row settlement paginator with preserved query, AR/EN paired locale, RTL/LTR and responsive dashboard shell. The document list does not unboundedly load all allocations.
- Authoritative actions: `POST admin.erp.finance.receivables.settlements.store` → `AccountsReceivableService::createAllocation`; `POST admin.erp.finance.receivables.settlements.post` → `AccountsReceivableService::postAllocation`. Both routes are under existing Admin ERP middleware, rate-limited and protected by canonical scope/permission/entitlement service checks.
- Permission and SoD: `finance.view` + `finance.ar.read` for browsing; `finance.manage` + `finance.ar.manage` for draft creation; `finance.post` + `finance.ar.post` for independent posting. Creator cannot post own allocation. Unentitled or module-disabled operations fail closed; source document must be posted; scope includes legal-entity identifiers; posting enforces posted outstanding maximum and Finance/GL approval. Account picker lists only active posting accounts of the selected legal entity. Business users do not type internal IDs.
- Audit: `enterprise.finance.ar_allocation_created` and `enterprise.finance.ar_allocation_posted` plus canonical Finance journal/ledger history. Retry uses stable form idempotency key; new business request generates new key.
- Failure and recovery: invalid foreign document returns 404; conflicting idempotency key or over-allocation returns validation error; failed posting leaves draft for authorized review. Database transaction and canonical journal state machine are unchanged. No manual rollback of posted journal is implied.
- UI states: selected document, no settlements, draft/postable by independent checker, posted linked journal, disabled create when no posting account or no manage permission, missing entitlement 403; unchanged list/pagination and language.
- No backend/mobile API contract, app navigation or fulfillment behavior changed.

## Explicit three-app impact decision

| App | Impact | Evidence |
| --- | --- | --- |
| Customer | No app or public API changes; customer invoice/ledger APIs untouched | Only backend Admin Finance routes / view / language / tests changed |
| Driver | No app/API changes; B2C DRIVER ONLY and Driver collection contracts untouched | No Driver/dispatch/collection table changes |
| Van | No app/API changes; B2B VAN ONLY and Van collection/remittance workflows untouched | No Van/dispatch/visit/stock table changes |

## Reproducible tests and release gates

`backend/tests/Feature/V2CollectionLinkedArSettlementWorkbenchTest.php`: seeded, legal-entity entitled Finance fixture; authorized AR/EN exact-record route and modal; draft create and retry idempotency; maker cannot post; independently authorized poster produces one additional journal and audit; poster retry safe; cross-store direct GET/POST denied; over-allocation rejected with unchanged financial rows. Reuse broader `EnterpriseAccountsReceivableFoundationTest` for GL approval, account and entitlement behavior and `FinanceWorkbenchTest` for general tab/projection/AR-EN/module-disabled cases.

Run: `php artisan test --filter=V2CollectionLinkedArSettlementWorkbenchTest`, `php artisan test --filter=EnterpriseAccountsReceivableFoundationTest`, plus full exact-head Repository Policy, V2 Isolation, localization and required-ci-gate (including MySQL/Redis upgrade and recovery). Browser/device tests for desktop/320-430px mobile, keyboard, RTL/LTR remain independent final evidence; no screenshot workflow is claimed.

## Source acceptance NOT completed by this PR

This is an **AR allocation workflow** against a posted receivable. An operator-typed `reference` does **not** cryptographically or referentially tie the allocation to an authoritative collected receipt, Van collection, Driver collection, bank statement or Treasury reconciliation. This PR does not wire independent `collection_transactions` / receipt IDs to Enterprise AR allocation and must not be advertised as complete P303 collection-linked settlement. Exact proof of collection source, allocation↔receipt uniqueness, over-collection controls across source ledgers, multi-store financial ownership and reconciliation/policy acceptance must be designed and tested before closing #2633, without duplicating P297 #2627 bank reconciliation or P288 aging #2618.

Maintain #2633 OPEN pending those requirements and final V2 audit. No main PR, production, tags or release artifacts authorized.
