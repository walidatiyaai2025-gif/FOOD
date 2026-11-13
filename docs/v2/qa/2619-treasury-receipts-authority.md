# FOODEX V2 — #2619 / P289 Treasury Receipts (التحصيلات)

- **Owner source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Sidebar P289 (Finance & Treasury). Parent #2503.
- **Isolated integration:** `Foodex_V2` only. No `main`, release, tag, APK or production deployment.
- **Issue/branch:** #2619 / `test/2619-v2-treasury-receipts-authority`.

## Navigation and actual workflow (Reuse → Extend → Compose → New Screen)

**Reuse** the existing Finance group → `erp_treasury` → `admin.erp.treasury.index?tab=receipts&store_id=<authorized-store>` (`EnterpriseTreasuryController::index` / `admin.enterprise-treasury` Blade). The existing group/route/projected authorized store is the canonical discoverable Treasury entry point. No new Sidebar item, screen, duplicate cash ledger, or unsupported Treasury route is introduced. **Extend only executable role/tenant/entitlement and maker/checker regression tests**. This is an existing responsive Dashboard with AR/EN and RTL/LTR support; use existing FOODEX components.

The receipts tab supports filtered/paginated real receipts, a target lookup of authorized cashboxes/bank accounts, new draft, distinct approval/posting, cancel draft, status, audit and idempotency. The workbench routes are `admin.erp.treasury.receipts.store`, `admin.erp.treasury.receipts.post` and `admin.erp.treasury.receipts.cancel`. `TreasuryReceiptService` owns transaction, authorization, data scope and status transitions.

## Authorization contract and proofs

| Action | Role / HTTP | Module & capability | Scope / safety |
|---|---|---|---|
| Read receipts | authenticated store-scoped `finance.view` | enabled/certified Treasury, `treasury.receipts.read` | store/company/organization filtered; unauthorized target store denied |
| Draft receipt | authenticated `finance.manage` | `treasury.receipts.manage` | authorized cashbox/bank account, positive amount, validated identifier and idempotency key |
| Approve and post | authenticated `finance.manage` | `treasury.receipts.approve` | distinct actor from `requested_by`; foreign receipt inaccessible; audit and posting event |
| Cancel draft | authenticated `finance.manage` | `treasury.receipts.manage` | cannot silently cancel a posted receipt; a separate reversal is needed |

Negative behavior is enforced at the controller and service boundary; Sidebar/form/button visibility is not an alternative security mechanism. The existing `RETAIL_FINANCE` fixture scopes users to one actual B2C store. A second foreign store and receipt target must be rejected, not mapped into the actor's store. The tests also cover no-access before module enablement, a read-only entitlement denying POST even when actor has `finance.manage`, AR/RTL and EN/LTR authenticated HTML, duplicate POST idempotency, same-actor posting denial and immutable posted receipts.

**Security invariants:** No blanket platform bypass, no B2B/B2C data bleed, no bypass of receipt maker/checker; B2B fulfillment remains VAN ONLY and B2C DRIVER ONLY. The tests grant test-only certification of the exact Treasury manifest version and do **not** certify or enable production runtime. No real user, ledger or financial record is mutated.

## Cross-application impact matrix

| Surface | Effect | Proof |
|---|---|---|
| Backend/API | **Test-only**, no public API schema/route/service contract delta | Only new Laravel Feature test for existing server routes and service |
| Admin Dashboard | **Existing functionality verified**, no UI code change | Actual localized HTTP response, store permission, capability checks, and mutation endpoints |
| Customer Flutter | **Not affected** | No order, credit, pricing, collection API/schema/token or customer navigation change |
| Driver Flutter | **Not affected** | No driver-only dispatch, wallet, collection, proof, settlement or mobile API contract change |
| Van Flutter | **Not affected** | No B2B-only van assignment, territory, route, trip or collection app API change |

No separate mobile implementation issue is justified without a real changed app-facing contract. Manual device/browser UX acceptance is deferred to V2 final (#2503 / #2750), **not** falsely asserted to have passed.

## Acceptance and CI

Test file: `backend/tests/Feature/V2TreasuryReceiptsNavigationAuthorityTest.php`.

Require exact final PR HEAD:
1. Repository Policy and FOODEX V2 Isolation success, base `Foodex_V2`.
2. Laravel/PHP syntax/Pint/static-analysis/security and Arabic/English checks success.
3. Full backend feature/integration suite, real MySQL/Redis install+upgrade+recovery validation, and terminal Required CI Gate success.
4. Confirm no code/source overlap with other workers and no unreviewed workflow or production changes.
5. Merge into `Foodex_V2` only, verify merge SHA and close #2619 as **implemented** with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. Keep #2503 open.

No green CI, successful merge, or production readiness is assumed merely because this test file was committed.
