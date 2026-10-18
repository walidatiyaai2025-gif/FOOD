# FOODEX Retailer 360 Intelligence Contract

Issue: #2220  
Program: #2208  
Dependencies: #2215 agent orchestration, #2217 actual sell-out ingestion, #2218 deterministic analytics, #2229 cohort-privacy contract.

## Purpose

Retailer 360 gives authorized wholesale management a governed analytical view of one retail account, including multi-store accounts. The result is deterministic server-side evidence that the Assistant may explain; the model is never the arithmetic or authorization authority.

The versioned result contract is `foodex.analytics.retailer360.v1`.

## Flow separation is mandatory

Two commercial flows are deliberately separate:

- **Sell-in** means goods received by the retailer from the requesting wholesale store. It is read from `retail_replenishments` and `retail_replenishment_items`, filtered by `source_wholesale_store_id`.
- **Sell-out** means actual retailer-to-consumer sales. It is read only through the authorized B2C analytics read model, which combines native FOODEX retail transactions with mapped external POS/API/CSV sell-out events.
- Wholesale purchases are never relabelled, inferred or substituted as retail sales.

The response therefore exposes `flows.sell_in.is_retail_sales=false` and `flows.sell_out.wholesale_purchase_inference=false`.

## Authorization and consent

Retailer 360 is a read-only Assistant tool. Requested retailer stores are passed through `AnalyticsReadModelService`; remote retail stores require active `RetailerSellOutAccessPolicy` permission for the requesting wholesale store and every requested store. Revocation therefore blocks the next read.

All direct SQL performed by the Retailer 360 service is downstream of that authorization and is constrained to the already-authorized retail store IDs and the requesting wholesale store ID. No model-authored SQL is accepted.

## Evidence and data quality

Every result includes:

- requested/account store IDs and window;
- wholesaler-specific sell-in value, units, replenishment count and last receipt;
- actual sell-out revenue, units, orders, refunds and cancellations;
- sell-out source state, last sync, coverage and quality;
- deterministic current-vs-prior equal-window trends;
- inventory cover from latest available stock and observed sell-out velocity;
- period sell-through with an explicit caveat that opening stock and other suppliers can make the ratio exceed inventory-depletion intuition;
- reorder cadence from observed wholesaler receipts;
- deterministic account-health score, risk/opportunity codes and confidence;
- data-quality badge and reasons;
- bounded SKU, category, date and store drilldowns.

Missing or partial sell-out remains explicit. It is not converted to zero consumer demand and is never replaced by sell-in.

## Account health

Account health is a deterministic evidence signal, not a causal prediction. It considers observable movement only, such as:

- material sell-out decline versus the prior equal period;
- refund/cancellation rates;
- reorder timing versus observed cadence;
- low stock cover where sell-out velocity is available.

Data-quality state changes confidence. Missing sell-out is treated as a visibility gap, not evidence of poor retailer performance.

## Anonymized peer benchmark

Peer benchmarking is privacy-gated by #2229. Issue #2229 has not yet established the canonical minimum cohort size or differencing policy, so #2220 fails closed rather than inventing a threshold.

Until #2229 is merged:

- benchmark state is `withheld` with reason `COHORT_PRIVACY_POLICY_PENDING_2229`;
- no peer rows, peer identifiers, cohort membership fingerprints or peer aggregates are queried or returned;
- own-history comparisons remain available;
- model/provider behavior cannot enable peer benchmarking.

When #2229 lands, Retailer 360 may consume that centralized privacy policy without changing sell-in/sell-out authority.

## Drilldown

The contract provides bounded drilldowns for:

- SKU;
- category;
- business date;
- retail store.

SKU sell-out includes actual native FOODEX B2C sales plus mapped external `sale` events. Refund/cancellation exceptions remain separate from sale units, matching the canonical sell-out semantics.

## Resilience and API surface

Retailer 360 is Laravel domain code and a typed Assistant read tool. It does not call Ollama/OpenAI-compatible providers, so core FOODEX and the deterministic analytical result continue to work when AI is disabled or unavailable.

No public `/api/v1` endpoint is added by #2220. The public OpenAPI contract is therefore unchanged.
