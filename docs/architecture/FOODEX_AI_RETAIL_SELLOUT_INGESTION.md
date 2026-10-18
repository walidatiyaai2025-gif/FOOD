# FOODEX Retail Sell-Out Ingestion Contract

Status: authoritative implementation contract for AI-09 / #2217.

## Purpose

FOODEX sell-out means actual retailer-to-end-customer activity. Wholesale replenishment or wholesale purchases are never inferred, relabelled, or substituted as sell-out.

The canonical acquisition model has two additive actual-sales sources:

1. Native FOODEX B2C orders, read directly from the existing transactional order authority.
2. Explicit external retailer feeds normalized through retail_sellout_events from POS/API/CSV connections.

External feeds are treated as external-only source segments. A connector must not replay native FOODEX orders as external events. Idempotency is enforced per connection and source_event_id, and each ingestion request also has a connection-scoped idempotency key.

## Security and consent

Connection creation, SKU mapping, ingestion, and revocation require the retailer store-scoped assistant.sellout.share permission and B2C ownership. Cross-store IDs fail closed.

Analytical visibility remains governed by the existing #2211 RetailerSellOutAccessPolicy. Wholesale callers cannot read a retailer feed merely because a connector exists; every analytics read re-authorizes the exact retailer and live purpose-bound grant.

No connector gives a model database access. The provider receives only deterministic analytics results.

## Normalized event contract

Each external row must resolve to:

- source event id and transaction id;
- external SKU mapped to one active B2C product in the retailer store;
- event type sale, refund, or cancelled;
- source timestamp normalized to UTC;
- business date normalized to Asia/Kuwait;
- positive quantity;
- non-negative gross, discount, and net amount;
- three-letter currency;
- optional source_updated_at.

Rows that cannot be normalized are rejected individually. Rejection reports store row number, code/message, source event id when present, and a payload hash rather than raw payload.

## Idempotency and reconciliation

- retail_sellout_ingest_runs is unique by connection + idempotency_key.
- retail_sellout_events is unique by connection + source_event_id.
- duplicate events are counted and not inserted again.
- every run reconciles rows_received = rows_accepted + rows_rejected + rows_duplicate.
- partial runs remain usable but lower the feed quality state.

## Analytics integration

AnalyticsFactMaterializer continues to compute native FOODEX B2C metrics through the #2212 semantic layer, then deterministically adds normalized external sell-out events for the same retailer/day. Wholesale replenishment remains in the separate wholesale_purchases fields and is not used to calculate sell-out revenue or units.

AnalyticsReadModelService exposes sellout_feed metadata for B2C reads:

- none: no actual native or external sell-out rows are available;
- partial: actual sell-out exists but an active external connection is incomplete/failed/never synced;
- available: actual sell-out is present and active external connections are complete.

Metadata includes last sync, coverage window, quality score, native/external row counts and the invariant wholesale_purchase_inference=false. Assistant tools therefore have an explicit machine-readable basis to state no or partial sell-out coverage.

## API

Authenticated /api/v1 endpoints:

- GET /assistant/sellout/connections
- POST /assistant/sellout/connections
- PUT /assistant/sellout/connections/{connection}/sku-mappings
- POST /assistant/sellout/connections/{connection}/ingest
- DELETE /assistant/sellout/connections/{connection}
- GET /assistant/sellout/ingest-runs/{run}

The API accepts source configuration and normalized business rows only. Credentials/secrets are intentionally not persisted by this contract.

## Acceptance evidence

RetailSellOutIngestionTest covers SKU mapping, duplicate source events, request idempotency, invalid SKU rejection, Kuwait timezone boundary, refunds, partial feed disclosure, cross-store isolation, connector revocation, and explicit no-feed behavior.
