# FOODEX V2 #2625 — P295 Treasury receipt action authority

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, legacy matrix P295 **القبض**, actual DOCX P284.
- Parent: #2503. Single Issue/branch/PR, integration `Foodex_V2` **only**.
- Domain: Finance & Treasury; authorized actor: scoped Retail Finance or qualified B2B Treasury operator.
- User task: browse, create receipt drafts, post with independent maker/checker and cancel unposted drafts, with reliable store entitlement and tenant isolation.

## Route and placement authority

**Reuse** the existing canonical Treasury Workbench `admin.erp.treasury.index?tab=receipts` and its existing authoritative POST routes:

- `admin.erp.treasury.receipts.store`
- `admin.erp.treasury.receipts.post`
- `admin.erp.treasury.receipts.cancel`

Reachability flows through the already guarded Finance > Treasury Workbench navigation and page-level Receipts tab; do not create a duplicate page, Sidebar item, CRUD service, payment event or an orphan route. The Treasury module and per-store entitlement remain fail-closed. No new navigation or permission is granted.

## Source defect and actual fix

Previously, the Receipts tab rendered **Create**, **Post**, and **Cancel** whenever `finance.manage` was true, even if the authoritative service rejected a specific action because `treasury.receipts.manage` or `treasury.receipts.approve` was missing. The same maker also saw a **Post** button for their own receipt even though the service correctly enforces independent approval. This is confusing and violates the rule that UI visibility must project server authority, not introduce a parallel security decision.

- `EnterpriseTreasuryController::index`: expose `canManageReceipt` iff scoped `finance.manage` **AND** the `treasury.receipts.manage` entitlement, and `canApproveReceipt` iff scoped `finance.manage` **AND** `treasury.receipts.approve` entitlement. Both are tenant/store-specific.
- `enterprise-treasury.blade.php`: show new-draft form and Cancel only when `canManageReceipt`; show Post only when `canApproveReceipt` **and** `requested_by != actor.id` on a draft.
- The source service `TreasuryReceiptService` remains authoritative for target/store/company/organization scope, active target/approval visibility, exact finance permission, entitlement, idempotency, SoD, audit and outbox. No frontend check is security authorization; forced HTTP attempts are still rejected by server checks.
- Existing Payment, Cheque, Reconciliation, Financing, Report and Cash Closing tabs are unchanged.
- The source refuses to enable Treasury if module readiness/certification is missing; the patch does not alter that policy.

## Executable test coverage

New `backend/tests/Feature/V2TreasuryReceiptActionAuthorityTest.php` exercises the true Laravel HTTP route:

1. Arabic RTL maker creates a real draft, sees Cancel but **not Post**, and direct self-approval is rejected; independent English LTR checker sees Post and can post it with persisted audit.
2. An assigned `RETAIL_FINANCE` user with `finance.manage` but only `treasury.receipts.read` sees a read-only ledger and no Create/Cancel/Post links; direct POSTs receive 403 without creating/modifying rows.
3. Cross-store requests and forged foreign receipt IDs are denied (403/404) with no posted mutation or audit record, even where the other store is otherwise entitled.

Existing foundational Treasury service tests remain in charge of idempotency, posted-event outbox, rollback, inactive targets, company visibility and other specialized finance invariants. `EnterpriseTreasuryWorkbenchTest.php` is owned by #2858/PR #3065 and is **not edited**.

## UI and accessibility

- Reuses existing Tajawal RTL/LTR, FOODEX responsive screen, readable localized button labels and native form/button keyboard semantics; no custom CSS or new screen.
- New and existing actions remain on exact-record rows; unauthorized actions do not appear. Create form uses a protected Cashbox/Bank Account lookup rather than a typed internal ID, 0.001 KWD scale, a date picker and bounded reference.
- Loading/empty/data/validation flows are handled by the current Workbench. No claim of manual desktop/mobile screenshot or device testing is made; manual UX acceptance is deferred to #2503.
- Arabic/English string catalogs are unchanged because all visible button labels already use `enterprise_treasury` translation keys.

## MOBILE IMPACT — Customer / Driver / Van

- Customer App: **NOT AFFECTED**, no customer identity, pricing, checkout, wallet or mobile API/Flutter change.
- Driver App: **NOT AFFECTED**, no B2C dispatch, delivery proof, wallet, collection or settlement API/Flutter change.
- Van App: **NOT AFFECTED**, no B2B routes, van trips, inventory, remittance or Flutter contract change.

The B2B=VAN ONLY and B2C=DRIVER ONLY invariants remain unchanged. Backend/Admin-only changed paths do not require unrelated mobile rebuilds.

## CI / completion

Required before merging: exact PR HEAD `Repository Policy`, `FOODEX V2 Isolation Gate` and `FOODEX Required CI Gate` all SUCCESS including Laravel full test, security/Pint/PHPStan, AR/EN and supported MySQL/Redis fresh-install/upgrade/recovery. Fix failures on **this same branch/PR**, never bypass or inherit older-head results. If all source scope is complete and exact-head tests pass, merge to `Foodex_V2` only, verify merged SHA/source branch cleanup, then close leaf with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503.

No `main` changes, merge/promotion, tag, release, staging, cPanel or production deployment.
