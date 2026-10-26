# FOODEX Order, Invoice and Delivery Lifecycle

Status: **Authoritative**
Updated: 2026-10-26
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
| B2B / Wholesale | Van for automatic routing; authorized manual Driver or Van dispatch | No automatic Driver fallback |
| B2C / Retail | Driver only | None |

For B2B, Smart Routing remains Van-first and never invents a Driver fallback. If no Van can be selected automatically, the order remains `awaiting_dispatch`. An authorized Order Operations user may explicitly override execution and choose either an eligible B2B Driver or an eligible Van.

For B2C, Driver assignment and execution remain authoritative; Van dispatch is not allowed.

## Operational state machine

Backend-authoritative transitions remain the only valid way to change fulfillment state.

Order states include `pending`, `confirmed`, `preparing`, `ready`, `out_for_delivery`, `delivered`, `failed`, and `cancelled`.

B2B execution may be owned by an explicitly assigned Driver or Van. The execution runtime records server-authorized assignment/execution events and preserves proof, failure reason, retry, collection and delivery completion rules.

For B2B, `ready` is a dispatch gate: the only manual order-status action exposed from `ready` is cancellation. A successful Driver or Van assignment advances the order to `out_for_delivery` automatically. If execution was assigned earlier, entering `ready` advances immediately to `out_for_delivery`.

Clients cannot set arbitrary states. Allowed actions are returned/enforced by backend services.

## Invoice and collection rule

Commercial values are immutable after invoice issuance. Operational status may change, but product lines/prices/totals cannot be silently edited.

For B2B COD, Van collection uses the authoritative invoice balance. `delivered` remains unavailable while an authoritative COD balance is outstanding. Account-credit balances remain finance truth and do not become fake cash collection work for the Van.

Correction requires explicit void/reissue/revision behavior with audit provenance.

## Driver lifecycle

Driver runtime supports Retail B2C Drivers and explicitly assigned B2B Drivers. B2B Driver work must originate from authorized manual Order Operations dispatch; automatic B2B routing remains Van-first. See `docs/workflows/DRIVER_WORKFLOW.md`.

## Van lifecycle

Van runtime remains the automatic B2B routing/execution authority and also accepts authorized manual B2B Van dispatch. See `docs/workflows/VAN_WORKFLOW.md`.

## Inventory

Checkout/order creation reserves stock through the authoritative store/warehouse context.

Cancellation releases reservations. Delivered/completed fulfillment consumes reservations according to the existing inventory service.

## Audit and observability

Routing decisions, Van assignment/reassignment, execution transitions, proof/failure/retry and finance mutations must retain actor/time/idempotency provenance. Runtime failures from Customer, Driver and Van surfaces are reportable to System Inspector with correlation and domain identifiers while sensitive payload/location data remains sanitized.

## Acceptance

Cross-store or cross-channel order, invoice, assignment or history access is denied server-side. B2B Driver execution requires an explicit authorized manual dispatch to a B2B Driver; B2C cannot execute through Van fulfillment authority.
