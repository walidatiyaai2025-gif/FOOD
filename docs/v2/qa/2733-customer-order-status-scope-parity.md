# V2 #2733 / MOB-C04 — Customer order-response scope guard

## Source and responsibility
Source: T115R01 / P118; parent #2503. Delivery target `Foodex_V2` only.
This increment addresses one concrete Customer order-status security boundary:
a successful HTTP response must not be treated as customer-authorized merely
because the request contained a store/channel scope. The backend remains the
primary authorization authority; the Customer client rejects mismatching data
rather than rendering it. No new route, role or permission is created.

## Behavior and evidence
- Scoped order lists must match the requested Store and Channel for **every** row.
- Channel-filtered lists must not show cross-channel B2B/B2C records.
- Order details must match the requested order ID, and must not cross the
  Store/Channel boundary when an explicit context is supplied.
- Existing positive-path status, invoice-outstanding fields and network errors
  remain available. Exceptions use existing `forbidden` and
  `invalid_response` codes; no new user-facing English labels are introduced.
- Hermetic Customer Flutter tests: `test/v2_customer_order_scope_parity_test.dart`.
  These prove scope-deny, wrong-ID, positive-path invoice/status behavior.

## Impact and remaining acceptance
- Backend/API: unchanged; server RBAC and tenant/store authorization remain
  authoritative and MUST still be tested independently.
- Dashboard: no changed page, permission, or route.
- Customer: modified only order-response validation, with targeted AR/EN-neutral
  regression tests. No layout or navigation changes.
- Driver: no code or B2C dispatch contract change.
- Van: no code or B2B-only fulfillment contract change.

**Incomplete source scope:** This is one tested parity increment, **not** full
MOB-C04 closure. Invoice retrieval/download, all notification event lifecycles,
storefront deep-link parity, end-to-end B2B/B2C status transitions, RTL/LTR UI,
offline recovery and real Customer runtime evidence still require inspection and
implementation as applicable on this same Issue/branch/PR, plus exact-final-head
Flutter and Required CI. Do not merge or close merely because the scoped tests
pass. Manual owner UAT is deferred to V2 final, but executable gaps are not.

No `main`, release, tag, signing, cPanel or production side effects.
