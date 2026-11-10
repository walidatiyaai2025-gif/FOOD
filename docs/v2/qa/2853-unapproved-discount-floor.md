# V2 P129 — Unapproved zero-value Customer Service concessions (bounded risk fix)

Issue #2853 · Source FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P129 · Parent #2503 · integration target **Foodex_V2 only**.

## Domain, authority and entry

**Domain:** B2B/B2C Orders → Customer Service support discounts. **Actor:** assigned `CUSTOMER_SERVICE_AGENT` or authorized `CUSTOMER_SERVICE_MANAGER`/`SUPERVISOR`, scoped to an active store/channel and `orders.customer_service_discount`. **Entry point:** existing Orders operations action `admin.operations.orders.customer-service-discount`; **pattern:** Reuse → Extend the existing `OrderCustomerServiceDiscountService::apply` server-authorized transaction. No new UI route, sidebar link, free-form approval flag, feature switch, permission or untrusted client calculation.

The controller requires authenticated active actor, canonical store-scope and permission checks; agents require current order assignment. The service locks the order, verifies pending/unpaid/unissued/undispatched state and authoritative untaxed snapshot, calculates revision and totals, writes row/payment/audit/notification transactionally. Customer Service has **no separate full-write-off checker authority**, so allowing the ordinary percentage to convert a positive-value order into zero was a material unapproved concession.

## Change implemented

Inside the locked, pre-mutation recalculation path, reject if the original subtotal is positive and the post-discount **rounded** grand total is zero or below. This includes a percentage such as 99.999% which rounds to zero at KWD three-decimal precision. Both AR and EN receive a clear field-scoped `percentage` validation error. The order, pending payment, discount revisions, audit and notification remain untouched on rejection. Valid positive-total concessions and unchanged idempotency behavior remain allowed. Rejecting a full write-off does **not** grant or simulate checker approval; a properly separate checker/workflow is a future requirement.

## Acceptance evidence

`backend/tests/Feature/V2CommercialDiscountZeroValueGuardTest.php` exercises:

- B2B wholesale pending order through real Dashboard HTTP, authorized Customer Service Manager; full 100% and KWD rounding-to-zero concessions rejected AR/EN
- No order total, pending payment, discount history, audit row or notification mutations from the rejected request
- Ordinary positive-value 10% concession still succeeds, exact idempotency replay creates one discount/audit
- Unprivileged actor denied by real route with HTTP 403, no financial mutation

Existing `OrderCustomerServiceDiscountTest` covers assignment, retail store scope, paid invoice/dispatch guard, positive revisions and notifications; `CommercialCreditEligibilityPolicyTest` independently tests unified B2B purchasing-power hard limits, and full Finance maker/checker suites remain unchanged. All required suites must pass on the **exact PR head**.

## Explicit remaining P129 acceptance blockers

This **is not a full profitability-floor or credit approval engine**. The present canonical order price/snapshot does not itself establish verified COGS per supplier-owned/FOODEX-owned line and the cost-basis governance needed to enforce an exact minimum gross margin. An unapproved *positive-but-loss-making* concession therefore remains a source requirement requiring separately approved and audited cost basis, threshold policy, profit/credit checker workflow and data/contract tests. Do not invent a margin floor (e.g. arbitrary 10%) or accept an untrusted request `approved=true`. Likewise a discount does not substitute for unified B2B credit exposure/receivables recalculation and pending-order reservation policy. Keep Issue #2853 **OPEN** until these remaining requirements are delivered and independently verified. This PR isolates and closes the deterministic full-write-off failure path only.

## Surface impact and delivery

| Surface | Impact | Reason |
| --- | --- | --- |
| Laravel Backend | **Affected** | Transactional Customer Service discount server validation and localized error |
| Dashboard | **Affected** | Existing action returns a scoped validation error on zero-value concession; no new UI structure or route |
| Customer Flutter | **No API contract change** | No customer-facing route, DTO, Dart, login, payment protocol or fulfillment contract modified. Authorized full waiver is rejected before order mutation; consumer still receives only legitimately updated totals |
| Driver Flutter | **No API contract change** | No delivery/assignment/Driver route or status contract modified; no mobile code changed |
| Van Flutter | **No API contract change** | No Van-only B2B dispatch, collection or assignment API modified; no mobile code changed |

Existing language selection returns Arabic/English field error. Responsive web, keyboard/focus and screen-reader evidence of the existing Orders modal must be collected separately in isolated staging; no visual proof is fabricated. For actual future mobile API behavior changes open the required per-app V2 MOBILE issues.

Gates: exact-head GitHub Repository Policy, V2 Isolation, Localization, Backend syntax/Pint/PHPStan/Composer security/OpenAPI, full Laravel tests and real MySQL/Redis clean installation/upgrade/recovery, required-ci-gate. One issue, one branch, one PR **to Foodex_V2 only**. No merge into main; no production deployment, tag, setup/update ZIP publishing or release claim.
