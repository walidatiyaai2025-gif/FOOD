# FOODEX AI Observability and Operations Contract

Issue: #2228 / AI-19

## Purpose

FOODEX AI observability extends the existing Management Assistant. It does not create a second chatbot, provider stack, tool framework, analytics warehouse, or data authority.

The existing Assistant turn `correlation_id` is the end-to-end **trace id**. It follows the request through orchestrator/provider/tool execution and the final response so an administrator can explain latency, fallback and failures without reading user prompts, customer payloads or credentials.

## Event contract

The bounded event store `assistant_observability_events` records operational metadata only:

- **turn** — started/completed/failed, actor/store identifiers, outcome and whole-turn latency;
- **provider** — provider/model, transport outcome, HTTP status, latency, retry attempt/fallback and normalized token usage;
- **tool** — registered tool key, completed/failed outcome and execution duration.

The store must never contain provider API keys, Authorization headers, prompts, Assistant messages, tool arguments, tool results, customer names/contact details, addresses, arbitrary request payloads, SQL or model chain-of-thought.

## Privacy and retention

- Metadata is redacted before persistence using a denylist that covers credentials, prompts/messages/content, arguments/payloads and customer/contact/address fields.
- Trace event writes are **fail-open for core FOODEX**. Missing/unavailable observability storage cannot block the Assistant or deterministic FOODEX operation.
- Default retention is **14 days**, configurable from `assistant.observability.retention_days` and capped at 90 days.
- Pruning is opportunistic and bounded to one attempt per day per cache namespace; an explicit `prune()` contract exists for scheduled/test execution.
- Admin diagnostics export is bounded (default 5,000 rows) and includes only safe operational columns, never event metadata/payload bodies.

This prevents logging from becoming a shadow warehouse of retailer/customer data.

## Access and UI placement

Placement decision: **Reuse -> Extend** the existing Admin Settings > FOODEX Assistant page at `admin.assistant-settings.index`. A new route/screen/sidebar domain is not introduced.

- `settings.view` or `settings.manage` may see aggregate operational health and current registered tool availability.
- Only `settings.manage` may inspect recent trace identifiers/events or export diagnostics.
- Trace inspection is bounded (latest 25 traces in the configured window, max 100 events for one selected trace).
- Existing provider capability probing remains explicit/manual; opening the page does not create provider traffic.

The same page uses the canonical Management Dashboard shell and remains AR/RTL + EN/LTR and responsive.

## Metrics

The Operations Center reports the configured time window (default 24 hours):

- Assistant request count;
- provider transport request/error count;
- average provider latency;
- tool call/error count;
- fallback rate;
- normalized total token usage;
- last safe provider/model/outcome metadata.

Business numbers are not computed from these traces.

## Failure behavior

Provider disabled/unavailable, model malformed, circuit-open, timeout, tool failure and Assistant turn failures are represented as stable operational outcomes. No failure record can grant authority, widen tenant/store/channel scope or change retailer sell-out consent.

Core FOODEX remains functional when the Assistant/provider is disabled and when observability storage is absent or unavailable.

## API impact

This feature extends existing authenticated Management web routes only. It adds no public `/api/v1` contract, so `docs/api/openapi.yaml` requires no change.
