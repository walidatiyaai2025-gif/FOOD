# FOODEX Canonical Semantic Metrics Contract

Issue: #2212  
Parent: #2208  
Version: 1.0.0  
Business timezone: `Asia/Kuwait`

## Authority

`App\Domain\Analytics\Semantic\MetricDictionary` is the machine-readable dictionary for business meanings. `SemanticMetricService` computes order/revenue/unit observations from an already-authorized server-side query and then **re-enforces the commercial flow channel**.

The LLM is not a calculator. It receives server-computed metric values plus definition/version/window/scope provenance.

## Commercial flow invariant

- `sell_in` means wholesale orders from FOODEX to a retail/business customer and is constrained to `orders.channel = b2b`.
- `sell_out` means retailer sales to end customers and is constrained to `orders.channel = b2c`.
- A metric observation is never emitted without one of those explicit flows.
- A caller cannot label B2C rows as sell-in or B2B rows as sell-out because the semantic service applies the authoritative channel again.
- Cross-flow comparison is allowed only as two separately labelled observations. Values are never silently pooled and relabelled.

## Canonical order metrics

The v1 calculator emits:

- all orders and recognized orders;
- gross revenue before order-level discounts;
- recorded discounts;
- net recognized revenue;
- refunded order count/value;
- cancelled order count/value;
- recognized units;
- average order value.

Cancelled and fully refunded orders are excluded from recognized revenue, units and AOV. Refund value in v1 is the authoritative value of orders whose order status is `refunded`; partial payment refunds remain payment-level facts and must not be invented from order totals.

The dictionary additionally defines margin, available stock, velocity, stock cover, reorder quantity and performance so downstream #2213/#2218 implementations reuse the same names/formulas instead of creating parallel semantics. Metrics that require cost, inventory, lead-time or policy facts are unavailable until those authoritative facts are supplied.

## Provenance contract

Every semantic snapshot exposes:

- dictionary version;
- flow and channel;
- Kuwait-local source window;
- currency;
- authorized scope (for example store IDs);
- server-computed source marker;
- `model_arithmetic_allowed=false`;
- AR/EN labels and explanations for every emitted metric.

This metadata travels with Assistant/dashboard analytical values and is the required basis for later agent evidence.

## Scope and permissions

The semantic layer does not discover authorization. The caller first builds a tenant/store/channel-authorized query using existing FOODEX RBAC and scope services. The calculator may narrow that query to the requested flow but never widens it.

## OpenAPI

#2212 introduces an internal semantic contract only. It does not add or alter a public `/api/v1` request/response schema, so there is no public OpenAPI schema change in this issue.
