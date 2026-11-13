# V2 #2560 — P230 Commercial exception approval: scoped pending-discount inbox

Source: FOODEX Unified RBAC/ERP/Sidebar source document, P230 (Catalog & Pricing), parent #2503. Isolated integration only: `Foodex_V2`.

## Placement and access contract

| Dimension | Decision |
| --- | --- |
| Domain/entity | Catalog & Pricing / independent Customer Service concession requests linked to orders |
| Role | B2B commercial approver with `orders.view`, `orders.customer_service_discount`, `orders.approve` in the same authorized Wholesale store; requester may not self-review |
| User goal | Find outstanding discount exceptions, open the exact order, independently approve/reject, audit the decision |
| Workflow | High-frequency pending queue → exact order → request review → approved history |
| Existing parent | Catalog → Sales Control → Operations / Orders |
| Placement | **Reuse** existing Order Operations review form with a narrowly filtered list and a Sales Control page tab. No new Sidebar entry, route, controller, status machine or approval service |
| Pattern/navigation | `PAGE_TAB`; `admin.commercial.sales-control` → `admin.operations.orders.index?channel=b2b&discount_view=pending&action_required=0`; back to Orders |
| Desktop/tablet/mobile web | Existing responsive shared Orders grid and exact-record review panel; same backend-scoped route, compact horizontally-scrollable page tabs |
| Locale | Existing AR/RTL + EN/LTR labels; `customer_service_discount.pending_description` added in both languages |
| Data/source | `order_customer_service_discount_requests` and existing Order Operations order details. Status is `pending`, not a fabricated demo record |
| Authority | Independent intersection of Orders read, Customer Service concession and Orders approve scopes, including channel/store; direct URL is blocked with 403/404 if unauthorized. Requesting actor cannot approve their own submission |
| States | No requests → existing empty Orders state; pending → review controls; approved/rejected → removed from pending list; replay → 409; stale/taxed/fulfilled approval → existing service validation |
| Module/readiness | Existing `OperationalTenantScope` and canonical Operations route grants; Catalog visibility alone never grants review. No new module/entitlement or bypass |

This resolves only the real Customer Service **discount-exception approval** workflow. It deliberately does not assert that every possible future profitability, credit-limit or cross-domain exception type is already supported. Those require their respective business owners and explicit source requirements.

## Security and acceptance

- A B2B review link is emitted only when the current Wholesale store is authorized for all three required permissions; no cross-store or Retail link is created.
- `discount_view=pending` is validated; the listing uses an `EXISTS` query over pending discount requests filtered through the same authorized Order store/channel scopes. Order data cannot leak through a display-only filter.
- Independently authorized reviewers open the actual order and reuse the already-tested `OrderCustomerServiceDiscountApprovalService`, whose locked transaction enforces maker/checker, idempotency and audit.
- The original `discount_view=approved` history remains separate and unchanged, including existing controlled access.
- Tests: `backend/tests/Feature/V2ExceptionApprovalWorkbenchTest.php` (AR/EN, actual pending request, exact-order review, approved/rejected removal, zero premature financial change, audit, deny, B2C and foreign-store restrictions, denial despite Catalog visibility).
- Existing regression: `V2ApprovedDiscountHistoryAuthorityTest`, `V2CustomerServiceDiscountApprovalTest`, `V2CommercialPricingGroupAuthorityTest`.

## Customer / Driver / Van impact

| App | Classification | Evidence |
| --- | --- | --- |
| Customer | Not affected | No app code, customer API, order status or payload changes; the previously supported approval mutation/notification remains canonical |
| Driver | Not affected | No dispatch, assignment or Driver API contract changes; B2C DRIVER ONLY preserved |
| Van | Not affected | No field route, VAN API or status changes; B2B VAN ONLY preserved |

No new Mobile issue is needed because this is Dashboard discoverability and server-side read filtering of already existing approval records; no mobile contract is changed.

## Operational verification required before integration

1. Focused tests and existing related regression suites, PHP/Pint, static analysis, AR/EN localization.
2. Exact-head GitHub Repository Policy, FOODEX V2 Isolation Gate and all FOODEX Required CI Gate jobs successful.
3. Merge conflict-free PR with `base.ref == Foodex_V2` only; record exact merged SHA.
4. Manual final responsive accessibility/UX and any broader source-inventory acceptance remains with V2 parent #2503/#2750; do not claim completed production UAT from CI alone.

No production deployment, default-branch change, release or tag.
