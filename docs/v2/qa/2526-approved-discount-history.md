# V2 #2526 — Approved discounts / الخصومات المعتمدة (P196)

## Source and placement

- Source: unified RBAC/ERP/Sidebar discussion P196, parent #2503; target: Foodex_V2 only.
- Domain/entity: Customer & Sales → Operations → Orders → existing exact-order Customer Service discount request and independently approved financial revision.
- Reuse → Extend: Operations → Orders grid → exact record drawer. No second discount workflow, route or Sidebar entry. The existing Orders close/back action remains the fallback; its mobile-web drawer is reused without a new renderer.
- Actor: active manager/supervisor with effective orders.view, orders.customer_service_discount AND orders.approve on the record's store and channel. Authorization comes from the existing scoped Orders controller and operational scope resolver; presentation does not grant access.
- Navigation type: CONTEXT_ACTION/read-only exact-record history, alongside the existing pending approval controls.
- Backend authority: OrderCustomerServiceDiscountApprovalService (#2824) remains the sole atomic maker/checker approval path, audit trail and financial mutation authority.

## Added behavior

- Authorized reviewer can read actual approved discount requests on the precise order, including percent, reason, requester, reviewer and approval timestamp. No synthesized approval or client-side financial recomputation.
- History stays visible once the order leaves pending status.
- Approved rows are server-side paginated (20/page, independent approved_discounts_page parameter), retaining order/channel/filter query context. Empty history adds no misleading placeholder.
- History is not loaded for unauthorized reviewers or for other order records. Pending approval actions are unchanged.
- AR/EN catalog parity: approved_history, approved_by, approved_at. Uses existing RTL/LTR responsive exact-order drawer, native article/time semantics and existing design tokens.
- Tests: V2ApprovedDiscountHistoryAuthorityTest checks authenticated approved readback after status change in both locales, persisted audit/revision and strict sibling-order isolation. Existing V2CustomerServiceDiscountApprovalTest covers permission denial, self-review, duplicate/rejected/stale paths.

## Cross-surface impact

- Backend: affected, bounded authorized read query; no endpoint, schema, migration, queue or release change.
- Dashboard: affected, existing Order detail surface only. Manual responsive/keyboard focus review and exact-head CI still required before final acceptance.
- Customer: no runtime/API/auth response schema change; existing approved-order notification remains authoritative.
- Driver: no runtime/API/dispatch changes; B2C Driver-only fulfillment unchanged.
- Van: no runtime/API/dispatch changes; B2B Van-only fulfillment unchanged.

## Acceptance qualification

This code covers previously invisible **approved Customer Service discount history**, rather than assuming that every possible commercial price-list, promotion or profitability concession is the same source P196. The broader approved-discounts business taxonomy and any missing entitlement/readiness or full visual acceptance must be verified against the owner source before the Issue can be declared completely accepted. Do not claim production release from this isolated implementation.

No main merge, tag, release, staging, cPanel or production action.
