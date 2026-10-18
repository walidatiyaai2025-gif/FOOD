# FOODEX Anomaly, Risk and Opportunity Signal Contract

Issue: #2222  
Parent: #2208  
Dependencies: #2218 deterministic diagnostics, #2220 retailer intelligence, #2221 demand/replenishment

## Purpose

FOODEX converts already-authorized deterministic analytics into proactive risk and opportunity signals. The signal engine is Laravel/domain code; the LLM may narrate a signal but does not calculate its business values, choose its tenant scope, or create orders.

The output contract is versioned as `foodex.ai.signals.v1`. The canonical deterministic diagnostic input is `foodex.analytics.diagnostic.v1` from #2218. Retailer and forecast inputs are accepted only after their server-side producer marks the row `authorized_scope=true`; unmarked rows are ignored rather than trusted.

## Deterministic signals

The engine evaluates:

- revenue and order drops/spikes against the authorized comparison period;
- refund and cancellation rate deterioration;
- stockout, low-cover and overstock states;
- SKU mix gains/losses;
- retailer inactivity against the retailer's own reorder baseline;
- rising retailer sell-through;
- likely reorder windows and cross-sell candidates;
- forecast deviation and potentially underserved demand.

Thresholds are code-owned and deterministic. Each emitted signal carries severity, confidence, baseline/current/delta values, affected entities, estimated impact, deterministic fingerprint, cooldown, localized AR/EN title, scope, and a reproducibility block containing the signal schema, diagnostic schema, stable input digest and evaluation timestamp.

## Evidence and false-positive controls

No signal is emitted when the upstream diagnostic evidence is `insufficient` or confidence is below `0.55`. Small movements stay below deterministic thresholds. The engine never converts missing evidence into a fabricated anomaly.

Inventory rows are accepted only when their `store_id` belongs to the authorized diagnostic scope. Retailer and forecast rows must come from an authorized server-side producer and carry `authorized_scope=true`. This flag is an internal domain boundary, not an LLM/user argument.

## Noise control

A fingerprint is stable across value changes for the same signal type, channel/flow, authorized store scope and affected entities. Repeated identical signals are suppressed during a severity-based cooldown:

- critical: 4 hours;
- high: 6 hours;
- medium: 12 hours;
- lower severities: 24 hours.

Severity escalation bypasses cooldown. A resolved signal may recur if the deterministic condition occurs again. A manually suppressed signal remains suppressed until its suppression window expires.

## Opportunity safety

Opportunity signals are explicitly `kind=opportunity` and `recommendation.is_recommendation=true`. Action keys are advisory workflow hints only. #2222 never creates, submits or modifies an order.

## Lifecycle and audit

`SignalLifecycleManager` supports the explicit lifecycle:

- `new -> acknowledged | resolved | suppressed`;
- `acknowledged -> resolved | suppressed`;
- `suppressed -> new | resolved`;
- `resolved -> new` for a later recurrence.

Suppression requires a human-readable reason. Every transition re-checks the actor against `assistant.use` and `reports.view` for every store in the signal's channel-scoped tenant boundary, then records `assistant.signal.lifecycle` through the repository `AuditLogger`. Cross-tenant lifecycle mutations return not-found semantics and are not audited as successful actions.

## Assistant/provider boundary

This capability extends the existing FOODEX Assistant analytics lineage and introduces no second chatbot, model router, SQL path or provider credential surface. Upstream Laravel services remain authoritative for analytics, retailer consent and forecast inputs; the model can only interpret the returned deterministic contract.

Core FOODEX transactions do not depend on the AI provider. Signal calculation and lifecycle code remain server-side and do not require Ollama/OpenAI availability.

## API / OpenAPI

#2222 adds an internal domain contract and no public `/api/v1` endpoint. Public OpenAPI is therefore unchanged.
