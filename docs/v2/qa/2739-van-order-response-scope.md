# FOODEX V2 — #2739 MOB-V03 Van order response-scope safety

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx T115R03/T115R04; parent #2503. **Integration:** `Foodex_V2` only, with no `main`, production deployment, release, tag or APK publication.

## Ownership, authority and route

- **Actor:** authenticated actively assigned Van operator; B2B wholesale order history, order detail and B2B direct-sale creation.
- **Runtime entry:** existing Van Orders workspace; existing `HttpVanOrderRepository.orders`, `order`, `createOrder`. No new screen, route or navigation destination.
- **Server authority:** existing authenticated `/api/v1/van/orders`, `/api/v1/van/orders/{id}` and `/api/v1/van/customers/{type}/{id}/orders` remain responsible for real role, assignment, tenant, channel, idempotency, finance and object authorization. The app-side check is a **second fail-closed response boundary**, not a replacement for server checks.
- **Change:** reject a Van order response whose `customer_type` is not B2B. Reject all records (do not partially display) if a filtered history request returns a different customer/store. Reject forged create responses for another customer/store and reject B2C create/history requests before HTTP. Never silently coerce invalid scope to a B2B record.
- **Preservation:** genuine B2B list, detail and create continue to work; create retains its original Idempotency-Key. No mutation, migrations, financial ledger or upload changes.
- **Tests:** `apps/van_app/test/van_order_b2b_response_scope_test.dart` uses hermetic MockClient with valid B2B, mixed B2C, foreign B2B customer/store, forged create response, Retail detail, and prohibited Retail input.

## Mobile impact matrix

| Surface | Status | Evidence |
| --- | --- | --- |
| Backend/API | No source change | The authoritative Van HTTP authorization rules and endpoints are unchanged by this slice. |
| Dashboard | No source change | No admin pages, links, permissions or reporting change. |
| Customer | No change | Customer app endpoints, checkout and identity untouched. |
| Driver | No change | B2C DRIVER ONLY, no driver API or Flutter changes. |
| Van | **Affected** | Existing Van order parser and HTTP response handling reject foreign B2C and customer/store scope; AR/EN labels and layout unchanged, existing error handling retained. B2B VAN ONLY. |

## Source-completeness limitation

This **bounded security increment does not close #2739 / MOB-V03**. Full Van physical stock/consignment, trip settlement, return/custody, and offline replay parity depend on authorized trip/stock/finance provenance and separate related workers (#2604, #2602, #2607, #2588 and #2605), none of whose files are touched. Keep this single issue-scoped PR **DRAFT and unmerged** until the remaining source acceptance, end-to-end app behavior, all exact-head Required CI checks, and safe file ownership coordination are evidenced. Do not represent this as a complete Van settlement or offline capability.

Manual browser/device/staging UAT is deferred to final V2 gate #2750 only; automated source, security, Flutter and CI requirements are **not** deferred.
