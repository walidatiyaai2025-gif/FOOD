# Commercial Profitability Event Feed

Issue authority: #1820. Integration target: `feature/enterprise-commercial-convergence`.

This feed exposes immutable, versioned commercial source facts for W7 profitability consumers without introducing a reporting ledger or analytics work on operational request paths.

## Contracts

Version 1 registers two Enterprise outbox event types:

- `CommercialProfitabilityOrderSnapshot`
- `CommercialProfitabilityCollectionAttribution`

Both are serialized through the existing Enterprise versioned-event registry and transactional outbox.

### Order snapshot

The order snapshot contains only canonical attribution needed by downstream profitability:

- order/store/channel/status identity;
- canonical customer-domain identifiers;
- currency and order monetary totals;
- quote/tier/promotion/coupon identifiers, without copying the complete pricing engine state;
- bounded order-line product/quantity/unit-price/line-total snapshots;
- aggregated canonical collection amount/count.

The event fingerprint is calculated from this canonical payload. The outbox event UUID is derived deterministically from event type, contract version, store, aggregate identity and payload fingerprint. A retry against the same commercial state therefore resolves to the same `enterprise_outbox_messages.event_id`; a real commercial-state change produces a new identity.

### Collection attribution

The collection event links only canonical identifiers and monetary state:

- order;
- invoice;
- remittance;
- collection account;
- store/channel/customer identity;
- amount, currency and remittance status.

Business free-text references, provider payloads and transport data are intentionally excluded.

## Compatibility rules

1. Consumers must explicitly support v1 through `enterprise_events.contracts`; unknown versions fail closed.
2. W7 consumers treat these events as source snapshots, not as Finance, Inventory, Treasury or Commercial write authority.
3. Consumer or BI outage must never roll back or synchronously block source-module analytics work beyond the local transactional outbox insert.
4. Re-delivery is expected and must remain idempotent by `event_id`.
5. Store/channel/customer scope carried by the event is mandatory for downstream isolation.
6. Product profitability uses the immutable order-line commercial snapshots; it must not reconstruct historical selling prices from current catalog data.
7. Cost is not fabricated by this feed. W7 must join authoritative valuation/cost snapshots separately.
8. No Customer, Driver or Van client contract is changed by this backend-only feed.
