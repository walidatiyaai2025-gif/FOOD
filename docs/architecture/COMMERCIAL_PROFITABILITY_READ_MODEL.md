# Commercial Profitability Read Model

Issue authority: #1823. Integration target: `feature/enterprise-bi`.

This W7 read model consumes the versioned W6 commercial profitability source facts and snapshots only authoritative cost that can be proven from the WMS valuation authority. It never becomes commercial, inventory, Finance or GL write authority.

## Source contract

Revenue provenance comes from W6 `CommercialProfitabilityOrderSnapshot` version 1 events in the existing Enterprise transactional outbox.

A refresh accepts only:

- event type `CommercialProfitabilityOrderSnapshot`;
- event version `1`;
- schema `foodex.commercial.profitability.v1`;
- matching envelope/source store identity;
- canonical B2B/B2C channel and currency identity;
- at most 100 immutable order-line commercial snapshots.

The canonical `orders` table is consulted in one bounded batch only to retain authoritative order creation time and store ownership. The source event remains the selling-price/discount provenance.

## Revenue semantics

For each immutable source order line:

- **gross revenue** = the source line total;
- **discount** = the source order discount allocated proportionally by line gross revenue, with the final line absorbing only the deterministic 0.001 rounding residual;
- **net revenue** = gross revenue - allocated discount;
- delivery and tax are not allocated into product margin.

These are commercial-order profitability measures, not recognized GL revenue. Order status remains an explicit dimension/filter so downstream surfaces can choose the business state they intend to analyze.

## Cost semantics

Cost uses the active WMS standard-cost valuation policy only when that exact policy row is provably valid at or before the source event time.

A cost is **not** reconstructed when:

- no authoritative valuation policy exists;
- the source order has no unambiguous warehouse cost authority;
- multiple store/product policies are ambiguous;
- the current policy was created or updated after the commercial event.

In those cases:

- `cost_state = missing`;
- unit cost, cost total and margin remain `NULL`;
- `unvalued_lines` makes the missing-cost state visible to BI consumers.

This deliberately prefers an explicit incomplete margin over fabricated historical cost.

## Projection and refresh

`enterprise_bi_profitability_facts` is a rebuildable BI projection, not a source ledger.

Refresh behavior:

- reads at most 100 source events per slice;
- advances by the Enterprise outbox integer cursor;
- writes only the BI projection;
- replaces an order projection atomically when a newer source snapshot arrives;
- ignores replayed/older source rows by `source_outbox_id`;
- keeps source event ID, fingerprint, outbox ID, policy identity and source event timestamp as provenance;
- uses a cPanel-safe queue job with a 45-second timeout and bounded continuation slices.

Source transactions do not wait for this projection.

## Query contract

The registered report is `commercial.profitability`.

Supported dimensions:

- date;
- product;
- customer;
- store;
- channel;
- currency;
- order status.

Supported measures:

- distinct orders;
- gross revenue;
- discount total;
- net revenue;
- cost;
- margin;
- unvalued line count.

Interactive ranges are capped at 31 days with page size capped at 100. Profitability totals must either filter a single currency or group by currency; mixed-currency summation fails closed.

All queries are store-scoped and aggregate directly from the BI projection without per-row operational queries. Large export execution continues through the existing Enterprise queued/chunked export authority.
