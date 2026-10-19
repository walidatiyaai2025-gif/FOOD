# FOODEX Typed AI Tool Registry Contract

Issue: #2214  
Parent: #2208  
Dependencies: #2210, #2211, #2212, #2213

## One registry, one execution gate

FOODEX continues to use the existing `AssistantToolInterface`, `AssistantToolRegistry` and `AssistantToolExecutor`. No second plugin/chatbot framework is introduced.

The registry is the model-visible allowlist. Every registered key has a typed `AssistantToolDefinition` containing:

- stable key and schema version;
- AR/EN business description;
- read/write mode (all #2214 tools are `READ`);
- JSON-style input and result schemas;
- required permission codes and `all` / `any` permission semantics;
- allowed B2B/B2C channels;
- timeout and row budget;
- data classification and sell-out consent requirement;
- authoritative source/provenance and freshness policy;
- audit event/redaction metadata.

`AssistantToolRegistry::providerTools()` exports OpenAI-compatible function descriptors. Dotted internal keys are encoded as double underscores in provider function names while the authoritative FOODEX key remains in `x-foodex.tool_key`.

## Pre-execution enforcement

`AssistantToolExecutor` performs, in order:

1. registered-key allowlist check;
2. input schema validation and reserved-field rejection;
3. store/channel scope-injection consistency checks;
4. active-user and #2211 principal resolution;
5. declared permission check;
6. retailer sell-out consent check where required;
7. audit record;
8. domain tool execution;
9. safe result decoration with schema/provenance/freshness/row-limit metadata.

Rejected requests never execute the tool. Tool arguments cannot supply actor identity, permission/role sets, database connection data or SQL.

## Initial read-only business tools

Compatibility tools remain registered for sales, order summaries/lookups, stores, customers, products, late/cancelled orders, drivers, inventory alerts and the daily brief.

#2214 adds:

- `analytics.summary` — #2213 materialized facts with #2212 metric semantics and explicit freshness;
- `analytics.diagnose` — #2218 deterministic period comparison, trend, mix/contribution, exception and inventory diagnostics with confidence/evidence metadata;
- `inventory.snapshot` — latest materialized inventory snapshots;
- `refunds.summary` — refunds, refunded payments and cancellations;
- `retailers.performance` — consented B2C sell-out comparison;
- `retailers.360` — #2220 governed wholesale-facing account intelligence combining supplier-specific sell-in with consented actual sell-out, deterministic health/data-quality evidence and bounded drilldowns; peer benchmarking remains fail-closed until #2229 lands;
- `wholesale.purchases` — consented retailer procurement totals/units;
- `margin.summary` — recognized revenue, cost coverage, COGS and gross margin only where product cost is known;
- `support.summary` — bounded customer-service/SLA workload without customer PII.

`products.performance` now supports `sort=top|slow` and a hard `limit<=25`.

## Security and data authority

The model never receives database credentials and cannot submit SQL. Laravel/domain services remain authoritative. Business numbers are computed by deterministic software. B2B sell-in and B2C sell-out remain explicitly typed and are not silently mixed.

Remote retailer tools require a live #2211 data-sharing grant for every retailer. Revocation is effective immediately because authorization is rechecked on every call.

## Limits and freshness

- analytics date window: <= 90 Kuwait business days;
- materialized fact response budget: <= 1,000 rows;
- retailer list: <= 25 per tool call;
- product rows: <= 25;
- operational rows retain existing bounded limits;
- #2213 current-data freshness threshold: 20 minutes.

Every executed result includes `_tool.model_sql_allowed=false`, source, schema version, timeout/row budgets, elapsed time and correlation ID.

## API/OpenAPI

#2214 adds an internal domain registry/tool contract only. It does not add or change a public `/api/v1` endpoint, so public OpenAPI remains unchanged.
