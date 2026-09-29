# FOODEX Order, Invoice and Delivery Lifecycle

Status: **Authoritative**
Updated: 2026-09-29
Related authority: `docs/architecture/PLATFORM_CUSTOMER_COMMERCE.md`

## Ownership

Every order has one exact:
- `store_id`;
- `channel` (`b2b` or `b2c`);
- domain customer identity;
- immutable commercial snapshots once invoiced.

A shared Platform Customer login never changes order ownership.

## Creation

Orders may originate from:
- Customer App authenticated checkout; or
- authorized Dashboard multi-line order creation.

Both paths must:
1. resolve exact store/channel/customer;
2. validate product ownership;
3. reprice server-side;
4. validate inventory/business constraints;
5. create order and item snapshots;
6. issue/link the invoice through the central InvoiceService;
7. create/link payment intent/state;
8. append history/audit;
9. emit scoped operational notification.

## Status state machine

Backend-authoritative operational transitions remain the only valid way to change status.

Current production states include:
- pending;
- confirmed;
- preparing;
- ready;
- out_for_delivery;
- delivered;
- failed;
- cancelled.

Clients cannot set arbitrary states. Allowed transitions are returned/enforced by backend services.

## Invoice rule

Commercial values are immutable after invoice issuance.

Operational order status may change after issuance, but product lines/prices/totals cannot be silently edited.

Correction requires explicit void/reissue/revision behavior with audit provenance.

## Driver lifecycle

A driver may act only on an assigned order matching that driver's store/channel authorization.

Driver actions may carry a note. Each status/note writes history with actor/time and emits the scoped Dashboard event required by the Notifications Center.

## Inventory

Checkout/order creation reserves stock through the authoritative store/warehouse context.

Cancellation releases reservations. Delivered/completed fulfillment consumes reservations according to the existing inventory service.

## Acceptance

Cross-store or cross-channel order, invoice, assignment or history access is denied server-side.

The release-blocking end-to-end contract is issue #414.
