# FOODEX Order, Invoice and Delivery Lifecycle

Status: **Authoritative**
Updated: 2026-10-10
Related authority: `docs/architecture/PLATFORM_CUSTOMER_COMMERCE.md`

## Ownership

Every order has one exact:
- `store_id`;
- `channel` (`b2b` or `b2c`);
- domain customer identity;
- immutable commercial snapshots once invoiced.

A shared Platform Customer login never changes order ownership. Order source does not select the fulfillment actor.

## Creation

Orders may originate from Customer App, Dashboard, Van-assisted order capture, or an authorized integration. All sources converge on the same authoritative Order domain and must:
1. resolve exact store/channel/customer;
2. validate product ownership;
3. reprice server-side;
4. validate inventory/business constraints;
5. create order and item snapshots;
6. issue/link the invoice through the central InvoiceService;
7. create/link payment intent/state;
8. append history/audit;
9. emit scoped operational notification;
10. resolve fulfillment through the channel policy below.

## Fulfillment actor policy

This boundary is locked:

| Channel | Fulfillment actor | Fallback |
| --- | --- | --- |
| B2B / Wholesale | Van only | None |
| B2C / Retail | Driver only | None |

For B2B, Smart Routing may assign an eligible Van. If no Van can be selected, the order remains `awaiting_dispatch`. A Driver must never be invented or used as a B2B fallback.

For B2C, Driver assignment and execution remain authoritative and must not regress while B2B is removed from Driver runtime.

## Operational state machine

Backend-authoritative transitions remain the only valid way to change fulfillment state.

Order states include `pending`, `confirmed`, `preparing`, `ready`, `out_for_delivery`, `delivered`, `failed`, and `cancelled`.

B2B Van execution additionally records immutable assignment/execution events for `accepted`, `picked_up`, `out_for_delivery`, `failed`, retry back to `out_for_delivery`, and `delivered`. Proof, failure reason, retry, collection and delivery completion are server-authorized and idempotent.

Clients cannot set arbitrary states. Allowed actions are returned/enforced by backend services.

## Invoice and collection rule

Commercial values are immutable after invoice issuance. Operational status may change, but product lines/prices/totals cannot be silently edited.

For B2B COD, Van collection uses the authoritative invoice balance. `delivered` remains unavailable while an authoritative COD balance is outstanding. Account-credit balances remain finance truth and do not become fake cash collection work for the Van.

Correction requires explicit void/reissue/revision behavior with audit provenance.

## Driver lifecycle

Driver runtime is B2C-only. Driver assignment, location, proof, collection/wallet and status APIs reject B2B execution after the Van cutover. See `docs/workflows/DRIVER_WORKFLOW.md`.

## Van lifecycle

Van runtime owns B2B assignment, routes, order execution, proof, failure/retry, collection/receipt, wallet/remittance and live tracking. See `docs/workflows/VAN_WORKFLOW.md`.

## Inventory

Checkout/order creation reserves stock through the authoritative store/warehouse context.

Cancellation releases reservations. Delivered/completed fulfillment consumes reservations according to the existing inventory service.

## Audit and observability

Routing decisions, Van assignment/reassignment, execution transitions, proof/failure/retry and finance mutations must retain actor/time/idempotency provenance. Runtime failures from Customer, Driver and Van surfaces are reportable to System Inspector with correlation and domain identifiers while sensitive payload/location data remains sanitized.

## Acceptance

Cross-store or cross-channel order, invoice, assignment or history access is denied server-side. B2B cannot execute through Driver and B2C cannot execute through Van fulfillment authority.
