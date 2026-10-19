# FOODEX Deterministic Analytics and Diagnostic Engine Contract

Issue: #2218  
Parent: #2208  
Dependencies: #2212 semantic metrics, #2213 analytics read model, #2214 typed tool registry

## Purpose

FOODEX computes business comparisons and diagnostic signals in deterministic Laravel code before any LLM narrative is produced. The model may explain a returned result, but it must not replace, recalculate or invent its numeric values.

The reusable result contract is versioned as `foodex.analytics.diagnostic.v1` and is exposed to the existing Assistant path through the typed read-only tool `analytics.diagnose`.

## Authority and access boundary

The engine consumes authorized #2213 read-model payloads. It does not accept SQL, database credentials or model-authored expressions.

Every current and comparison-period read is independently re-authorized by `AnalyticsReadModelService`. Direct store/channel scope, remote retailer sell-out consent, retention windows and freshness rules therefore remain server-enforced. If the previous period is outside the authorized/available window, the engine returns insufficient evidence instead of synthesizing a comparison.

Dimension queries are server-authored and receive only store IDs already authorized by the read model:

- retailer/account contribution;
- SKU contribution;
- category contribution;
- channel contribution.

SKU/category and margin diagnostics are omitted when the actor lacks `catalog.view`.

## Deterministic calculations

The engine returns:

- current and immediately preceding equal-length period totals;
- absolute and percent change for orders, revenue, units, refunds, cancellations, payments and retailer procurement;
- explicit `zero_previous_denominator` instead of infinite or fabricated percentages;
- least-squares daily trend/velocity for net revenue, units and recognized orders;
- current/previous mix share, percentage-point mix shift and contribution to revenue change;
- refund/cancellation rates and top deterministic contributors;
- inventory cover in days using current available units and observed unit velocity;
- gross-margin comparison only where authorized cost data exists.

Refunds and cancellations are reported as exception indicators. They are not labelled as causes of recognized revenue movement because correlation alone is not causal evidence.

## Evidence, sample quality and confidence

A diagnostic response includes an `evidence` block with:

- sufficient/insufficient state;
- confidence score and level;
- current/comparison fact-row and distinct-day counts;
- freshness metadata;
- machine-readable reasons such as missing comparison data, incomplete/stale facts, sparse windows or currency incompatibility.

Trend calculations require at least three distinct business days. Sparse, missing or incomplete evidence is surfaced explicitly rather than turned into a narrative cause.

## Model guardrails

Every diagnostic response declares:

- `model_arithmetic_allowed=false`;
- `model_may_override_computed_values=false`;
- `causal_inference_from_correlation_allowed=false`;
- insufficient evidence requires qualification.

The LLM is an interpreter of the result schema, never the arithmetic authority.

## Resilience and API contract

The engine is a Laravel domain service and typed Assistant tool. Core FOODEX commerce remains independent of provider availability; disabling or losing the AI provider does not affect transactional operation.

No public `/api/v1` endpoint is added or changed by #2218, so the public OpenAPI contract is unchanged.
