# V2 #2602 — P272 Van trip settlement: source and authority audit

Parent: #2503. Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P272, Fleet & Van.
Target: **Foodex_V2 only**. No `main`, release, tags, cPanel, deployment or production data operations.

## Placement and actual user action

- Domain: Fleet & Van; entity: Van trip financial settlement and custody.
- Actor: authorized B2B Van finance supervisor / maker-checker, with store- and channel-effective `finance.view` for reads and separate approved finance action permissions for any settlement.
- Goal: identify **one real trip**, reconcile its receipts, collections, remittances and consignment/returns, approve or reject closeout, and retain an immutable audit.
- Workflow: high-risk, transactional settlement after an evidenced trip lifecycle; data density: per-trip collections, currencies, remittances, adjustments, stock and exceptions.
- Placement order: **Reuse → Extend** the existing administration hub Van Finance Support and the canonical Field Operations Van route/visit experience. **Do not** introduce a parallel finance Sidebar, a fake trip ID, or a receipt balance displayed as an approved trip settlement.
- Existing parent entry points: `/admin/administration` → Van Finance Support (`/admin/van-finance-support`); Field Operations → Visits & Routes for actual B2B Van visits. The authorized route, future tab/popup and handoff must use their current AR/EN, RTL/LTR, responsive pattern.
- Navigation currently has custody wallets/collections/remittances/reconciliation, **not an implemented trip-settlement transition**. P272 remains source-incomplete. A standalone/direct-URL-only page is not an acceptable replacement.

## Verified schema and source boundaries

`backend/database/migrations/2026_10_05_193000_create_van_visit_foundation.php` defines `van_visits` using `actor_user_id`, `customer_type`, `customer_id`, nullable `store_id`, optional `order_id` and visit lifecycle; **it does not own a canonical trip-session ID or Van vehicle ID**.

`backend/database/migrations/2026_10_05_192300_create_collection_custody_remittance_domain.php` defines `collection_accounts` by actor/store/currency, `collection_transactions`, `collection_allocations` by invoice, `custody_ledger_entries`, `remittances` and `remittance_allocations` by collection transaction. Those ledgers **have no immutable visit/trip allocation**. Inferring trip cash from an account balance, Van actor, arbitrary time window or order ID can double count across repeated visits and assign one receipt/remittance to the wrong trip. Nothing in the existing schema proves one-to-one order↔visit↔collection↔trip closeout.

Existing `FieldOperationsFinanceService` groups *actor account* custody and remittances. This is not a trip-specific settlement engine. Existing `DeliverySettlementService` summarizes *order/invoice* payment state, not route/trip custody. No settlement can be certified without a trusted source relationship and financial SoD.

## Implemented bounded regression, NOT end-to-end P272

`backend/tests/Feature/V2VanTripSettlementProvenanceTest.php` exercises the **real existing admin HTTP finance support** and source schema:

1. An authorized Super Admin may inspect a B2B Van collection account in English/LTR and Arabic/RTL, and a same-Van Retail/B2C collection account is absent from the B2B-only result.
2. A roleless operator is forbidden from the finance route.
3. Read-only GET creates no custody or remittance writes, and the current source schema does not claim trip attribution that it cannot prove.

No new finance posting, settlement approval, module entitlement, migration, public API, Flutter screen or navigation route is added. The test validates an existing boundary and protects against falsely interpreting that actor-account view as a trip settlement. It **does not satisfy full issue acceptance**.

## Required authoritative design and completion steps

1. Define an immutable trip/session identity (Van, authorized store/tenant/channel, route, start/end and actor), link Van visits/orders to it, and handle legacy unknown provenance without inventing attribution. Lock and audit trip lifecycle/SoD; B2B uses VAN ONLY, B2C uses DRIVER ONLY.
2. Record explicit per-trip collections, reversals, currency, invoice allocation, cash custody and remittance allocation under concurrency/idempotency rules. One receipt must never settle multiple trips without an explicitly tracked split allocation.
3. Reconcile Van stock load/unload, consignment, sales/returns, shortages, adjustments and damaged/lost stock with authoritative WMS and financial posting; prohibit closeout for pending/ambiguous evidence. Decide legal ownership/valuation explicitly, do not reclassify legacy stock.
4. Implement separate maker/checker actions with server-side permission, store, channel, module entitlement/readiness, audit trail, protected approval and safe retry. A fail-closed human recovery workflow must exist for ambiguous legacy records.
5. Extend the canonical Van Finance Support/Field Operations UI after current owners release shared file fences; use authorized business lookups, AR/EN localization, responsive 320–1280+ layout, focus/keyboard states, no direct inaccessible URL.
6. Add real Laravel HTTP negative/positive/cross-store and actual-trip/finance/stock reconciliation tests, exception/retry tests and mobile parity. Run exact-final-head Repository Policy, V2 Isolation and Required CI (Laravel/Pint/PHPStan/AR-EN/MySQL/Redis) before a merge.

## Ownership fences and app impact

At initial preflight:
- #2607 / PR #3313 owns `VanFinanceSupportController.php`, `FieldOperationsFinanceService.php`, Van Finance Support Blade and AR/EN locales; P277 closeout **is not P272 trip settlement**.
- #2606 / PR #3311 owns Field Operations Van visit controller/Blade and related locales; P276 route activity is **not a finance settlement**.
- #2604 / PR #3330 owns `VanInventoryReadService.php`; its Van inventory read is **not a reconciled custody movement**.
- #2588 / PR #3317 owns WMS forged-Van-target guard. The current PR changes **only the new P272 test and this note**.

| Surface | Current increment | Remaining actual P272 effect |
| --- | --- | --- |
| Backend/API | Existing admin HTML read contract verified; no API schema changes | Trip identity, settlement ledger, authorization, approval/reversal endpoints and audit needed |
| Dashboard | Existing Finance Support accessed by real HTTP; no UI edits | Real approved trip settlement entry/approval/exception flow required |
| Customer | No public API or Customer app change in this test-only increment | Review invoice/collection balances upon actual future posting; link separate V2 Customer issue if changed |
| Driver | No B2C Driver API or app change; no B2B Driver fallback | None planned under B2B=VAN ONLY; link separate Driver issue if later affected |
| Van | No Van app code change in this test-only increment | **Affected in full P272**: live per-trip assignment, receipt/stock proof, offline replay, settlement exception. Coordinate with #2739; app and API tests required before closing P272 |

## Release and acceptance state

**SOURCE INCOMPLETE / NO SAFE MERGE AUTHORIZATION.** This work must remain draft/open unless an independently valid implementation and all source requirements are completed. No claim of fresh-install/upgrade success, full Laravel pass or live-account proof is inferred from source inspection; attach exact head CI evidence when available. Once full source and CI are green, manual owner/browser/device UAT may be recorded as `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503, not before.

No `main` integration, tags, releases or production deployment.
