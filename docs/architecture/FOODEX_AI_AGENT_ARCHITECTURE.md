# FOODEX Agentic AI Architecture and Domain Contract

Status: **Authoritative architecture contract**
Program: **AI-PROGRAM / #2208**
Issue: **#2209 / AI-01**
Baseline reviewed: main at a4fc5cb2394a62a35fe60847150b4529d4886ba5
Runtime: **existing Laravel Management Dashboard + existing FOODEX Assistant**
Provider stance: **optional; OpenAI-compatible/Ollama/Qwen; never a business authority**

This contract defines how FOODEX evolves the existing read-only Assistant into an agentic commerce-intelligence platform. It does not create a second chatbot, second data authority, second authorization system, or direct model-to-database path.

The existing Assistant V1 charter remains the historical/runtime baseline. This document is authoritative for the AI-PROGRAM evolution beyond V1 when the two documents differ on future architecture.

## 1. Mandatory architecture invariants

1. **One Assistant stack.** Extend the current Assistant routes, conversation state, brain seam, tool registry, page context, settings and UI. Do not introduce a parallel chatbot or alternate conversation store.
2. **Laravel remains authoritative.** Authentication, RBAC, store/tenant/channel scope, retailer sell-out consent, metric calculation, business rules and all writes are server-owned.
3. **No model SQL.** No provider receives database credentials, SQL-generation capability, unrestricted repository access, shell access, arbitrary PHP execution or a generic query tool.
4. **Deterministic numbers.** Revenue, margin, units, AOV, cancellations, stock, velocity, forecasts, reorder quantities and comparisons are calculated by deterministic FOODEX services/read models. The model may explain, summarize or prioritize those results.
5. **Read-only by default.** The current ASSISTANT_READ_ONLY fence remains mandatory until #2224 lands the separate draft -> explicit approval -> execute contract.
6. **Authorization is per call.** Every tool invocation and every approved action is re-authorized server-side. Conversation memory, browser page context and model output never grant access.
7. **Sell-in and sell-out are different facts.** Wholesale purchases from FOODEX are sell-in. Retailer sales to end customers are sell-out. They may be compared only as separately identified datasets with explicit coverage and policy.
8. **Provider failure is isolated.** FOODEX commerce and the deterministic Assistant path remain usable when external AI is disabled, unreachable, malformed or incapable.
9. **Evidence is first-class.** Analytical answers expose scope, time window, freshness/coverage and evidence/provenance sufficient to reproduce the deterministic result.
10. **AR/EN and auditability are contract requirements.** User-facing AI output, errors, evidence labels and approvals follow FOODEX localization and audit conventions.

## 2. Current implementation inventory

### 2.1 Existing runtime seams to preserve

| Area | Current authority | Decision |
| --- | --- | --- |
| Management Assistant HTTP surface | backend/app/Http/Controllers/Admin/AssistantController.php + backend/routes/web.php | **REUSE + EXTEND.** Keep the existing /admin/assistant conversation surface. #2215 may delegate turn execution to an orchestrator behind this controller; #2225 may evolve presentation without a second route family. |
| Brain contract | App\Domain\Assistant\Contracts\AssistantBrainInterface | **REUSE as compatibility facade.** The existing respond(AssistantBrainRequest): AssistantBrainResult contract remains valid while #2215 introduces the multi-tool orchestrator behind or beside this seam. |
| Hybrid brain | App\Services\AssistantHybridBrain | **EXTEND then narrow responsibility.** It currently combines deterministic routing with the external intent router. #2210 owns provider gateway evolution; #2215 owns orchestration. Do not create an unrelated second brain stack. |
| Deterministic conversation engine | App\Domain\Assistant\Conversation\DeterministicBrain and parser/resolver classes | **REUSE.** Keep deterministic greetings, intent/entity/time parsing and safe fallback as a non-provider path. Multi-tool planning may supersede single-intent routing only inside the same Assistant stack. |
| Tool contract | App\Domain\Assistant\Contracts\AssistantToolInterface | **EXTEND IN PLACE.** #2214 adds typed metadata/schema/policy/evidence around the same registry concept rather than creating a second plugin framework. |
| Tool registry | App\Domain\Assistant\Tools\AssistantToolRegistry | **EXTEND IN PLACE.** It stays the allowlist boundary. A model can request only registry-advertised tools and validated schemas. |
| Business tools | AssistantBusinessToolSet / AssistantBusinessReadService | **REUSE then migrate reads.** Existing exact/scoped reads remain compatibility tools. #2212/#2213/#2218 may move calculations onto canonical semantic metrics/read models without duplicating them. |
| Operations tools | AssistantOperationsToolSet / AssistantOperationsReadService | **REUSE then migrate reads.** Same rule as business tools. |
| Page context | App\Domain\Assistant\Support\AssistantPageContext | **EXTEND.** #2211 makes principal/policy/consent explicit; #2216 expands page/session context. Browser-supplied IDs remain hints only. |
| Conversations/messages/state | AssistantConversation, AssistantMessage, AssistantConversationState | **REUSE + EXTEND.** #2216 owns additional context/memory semantics. Do not introduce a parallel chat/session database. |
| Runtime switch | App\Services\AssistantRuntimeSettings + backend/config/assistant.php | **REUSE.** Disabled and read-only fail-closed behavior remains mandatory. |
| Provider settings | App\Services\AssistantProviderSettings | **EXTEND.** #2210 adds model registry/capabilities/fallback policy while preserving existing settings compatibility. |
| Provider HTTP client | App\Services\AssistantExternalAiClient | **REPLACE INTERNAL ROLE, NOT STACK.** #2210 evolves this exact OpenAI-compatible/Ollama seam into a provider gateway; current Qwen normalization and endpoint safety are retained as compatibility behavior. |
| Assistant permission | assistant.use in backend/config/permissions.php | **REUSE + EXTEND.** It remains the base access permission. #2211/#2224 may add narrower data/action permissions; they must not weaken existing domain permissions. |
| Existing UI | backend/resources/views/admin/_assistant-chat.blade.php + assistant-chat JS/CSS | **REUSE + EXTEND.** #2225 owns page-aware agent UX; no second assistant drawer/application. |

### 2.2 Current supported single-turn intent keys

The current registry/router vocabulary is the compatibility baseline:

- sales.summary
- sales.compare
- orders.summary
- orders.lookup
- stores.summary
- stores.compare
- customers.summary
- customers.activity
- products.performance
- orders.late
- orders.cancelled
- cancellations.summary
- drivers.status
- drivers.assignments
- inventory.alerts
- brief.daily

New capabilities must be added through the same typed registry. Existing keys must not silently change meaning.

### 2.3 Current operational settings

The existing controls remain authoritative compatibility inputs:

| Control | Current source | Contract |
| --- | --- | --- |
| ASSISTANT_ENABLED / assistant_enabled | env or dashboard Setting | Global feature gate. Missing/invalid storage fails closed. |
| ASSISTANT_READ_ONLY | env/config | Mandatory safety fence until action governance exists. |
| ASSISTANT_RETENTION_DAYS | env/config | Conversation retention input. |
| ASSISTANT_CACHE_SECONDS | env/config | Reserved cache seam; scope-safe cache design is required before business-result caching. |
| ASSISTANT_RATE_LIMIT | env/config | Per-user/IP Assistant rate limit. |
| ASSISTANT_AI_ENABLED | env/dashboard provider config | Optional provider enablement only. |
| ASSISTANT_AI_API_URL | env/dashboard provider config | OpenAI-compatible endpoint; endpoint validation remains mandatory. |
| ASSISTANT_AI_MODEL | env/dashboard provider config | Current model selector; becomes model-registry input in #2210. |
| ASSISTANT_AI_API_KEY | env/encrypted Setting | Secret; never returned in prompt/log/UI/API payload. |
| ASSISTANT_AI_TIMEOUT_SECONDS | env/dashboard provider config | Bounded provider timeout. |

## 3. Target runtime and dependency direction

The runtime dependency order is normative:

~~~text
Management Dashboard / contextual FOODEX page
        |
        v
existing Assistant HTTP API + conversation identity
        |
        v
AI Principal Context + Policy/RBAC/Tenant/Channel/Retailer-Consent gate
        |
        v
Agent Orchestrator
   |---- deterministic dialogue/router fallback
   |---- typed Tool Registry
   |---- canonical Semantic Metric Layer
   |---- analytics/read-model services
   |---- page/session context + bounded memory
   |---- approved Knowledge/RAG sources
   |---- approval workflow for future writes
   |---- observability/evaluation hooks
        |
        v
authoritative FOODEX domain services/read models
        |
        +---- Wholesale sell-in facts
        +---- approved Retail sell-out facts
        +---- inventory/orders/customers/finance/operations
        |
        v
authoritative transactional storage / approved external feeds

Provider gateway is a side-effect-free reasoning adapter used by the orchestrator.
It never sits between FOODEX services and the database and never receives database credentials.
~~~

Allowed dependency direction:

**UI -> Assistant controller -> identity/policy -> orchestrator -> typed tools -> domain services/read models -> persistence.**

Provider calls may help classify, plan, summarize or explain, but any tool request produced by a provider returns through the registry and policy gates before execution.

Forbidden directions include:

- Provider -> database
- Provider -> Eloquent model/repository directly
- Provider -> arbitrary URL fetch
- Provider -> generic SQL executor
- Provider -> write service without approval
- UI/page context -> trusted tenant/store authority
- conversation memory -> authorization grant

## 4. Authority and data boundaries

### 4.1 Identity and authorization boundary

#2211 owns the concrete implementation, but the architecture requires one server-created **AiPrincipalContext** per Assistant turn and per tool/action re-authorization.

Normative fields:

| Field | Meaning |
| --- | --- |
| actor_user_id | Authenticated FOODEX user id. |
| locale | Effective ar/en locale. |
| role_codes | Effective roles after normal FOODEX role resolution. |
| permission_codes | Effective permission snapshot or policy resolver reference. |
| channel | Effective b2b/b2c channel when scoped. |
| active_store_id | Current exact store when one is selected. |
| permitted_store_ids | Server-derived set for this request; never provider-authored. |
| page_context | Validated current route/entity hints only. |
| retailer_data_grants | Server-derived sell-out consent/share grants and purpose. |
| correlation_id | Request/trace correlation id. |

The provider may receive only the minimum subset needed for reasoning and must not receive hidden role tables, secrets, raw access tokens or unrelated tenant identifiers.

### 4.2 Business fact boundary

Authoritative facts come from domain services/read models. The semantic layer introduced by #2212 owns metric definitions; #2213 owns reusable analytical read models/fact pipelines.

Every analytical value passed to a model must carry:

- metric key/version;
- dataset class: SELL_IN, SELL_OUT or other explicit domain fact;
- entity scope;
- channel/store scope;
- time window and timezone;
- computation timestamp;
- source/provenance reference;
- coverage/freshness metadata;
- deterministic value/unit/currency when applicable.

The model may not recompute or relabel those values.

### 4.3 Sell-in vs sell-out contract

**SELL_IN** means Wholesale/B2B sale or purchase flow between the FOODEX wholesale principal and a customer/retailer.

**SELL_OUT** means the retailer's own actual sale to an end customer from a Retail tenant or approved imported/connected source.

Rules:

1. A wholesale order to a retailer is never evidence of sell-out.
2. Sell-out may be read only when the exact retailer/store relationship and consent/policy permit the requesting principal to analyze it.
3. Revoked consent blocks future sell-out reads immediately.
4. Comparative responses keep sell-in and sell-out values separately labeled even when shown in one card/chart.
5. Missing sell-out coverage is reported as unavailable/partial, never inferred from sell-in.
6. Cross-retailer aggregation must satisfy #2211 leakage/privacy policy before it becomes a tool capability.

## 5. Target typed contracts

These names and semantics are authoritative architecture. Their concrete PHP classes/interfaces are introduced only by the downstream issue that owns the subsystem, preserving the one-Issue ownership rule.

### 5.1 Agent orchestrator

**AgentOrchestratorInterface**

Input: **AgentTurnRequest**

Required AgentTurnRequest fields:

- conversation_id/public id;
- user_message;
- AiPrincipalContext;
- AssistantContextEnvelope;
- AssistantMemorySnapshot;
- correlation_id;
- bounded execution budget.

Output: **AgentTurnResult**

Required AgentTurnResult fields:

- localized final message;
- status: COMPLETE, NEEDS_CLARIFICATION, DEGRADED or DENIED;
- ordered tool execution summaries;
- deterministic cards/data;
- safe navigation actions;
- evidence list;
- scope/freshness summary;
- suggested prompts;
- optional approval draft;
- provider metadata safe for users/telemetry;
- updated memory delta;
- failure envelope when degraded/denied.

The orchestrator cannot execute arbitrary functions. Every executable step must resolve to a registered typed tool or the approval subsystem.

### 5.2 Tool definition and execution

The current AssistantToolInterface and AssistantToolRegistry are the compatibility foundation.

#2214 extends them with an authoritative **AssistantToolDefinition** describing:

- key and schema version;
- localized description;
- mode: READ by default; DRAFT/EXECUTE only after #2224;
- typed input schema;
- typed output schema;
- required FOODEX permission(s);
- data classification;
- allowed channel(s);
- store-scope behavior;
- whether retailer sell-out consent is required;
- timeout/result-row budget;
- evidence/freshness requirements;
- idempotency requirements for future execution tools.

**AssistantToolRequest** remains server-created. Its target semantics include:

- call_id;
- actor/principal context reference;
- locale;
- channel/store;
- validated arguments/entities;
- validated page/session context;
- correlation id;
- execution budget.

**AssistantToolResult** target semantics include:

- deterministic data;
- cards;
- safe actions;
- canonical references;
- AssistantEvidence[];
- scope summary;
- freshness/coverage;
- warnings;
- no secret/provider prompt content.

A provider may propose a tool key and arguments only. The server validates the key against the registry, validates typed arguments, rebuilds authority from the current user/session and then executes.

### 5.3 Evidence

**AssistantEvidence** is a typed, non-secret provenance item.

Required fields:

- evidence_id/call reference;
- source_type such as DOMAIN_READ_MODEL, SEMANTIC_METRIC, APPROVED_KNOWLEDGE;
- source_name/version;
- entity type and canonical reference where safe;
- store/channel/data-class scope;
- time window;
- observed/computed timestamp;
- freshness state;
- coverage state;
- metric key/value/unit when relevant.

Evidence must be sufficient to explain where a claim came from without exposing SQL, credentials, private prompts or cross-tenant identifiers.

### 5.4 Context and memory

#2216 owns implementation of:

**AssistantContextEnvelope**
- validated current page path;
- canonical page entity references;
- active store/channel;
- locale;
- client capability hints that do not grant authority.

**AssistantMemorySnapshot**
- last intent/task;
- last authorized canonical entities;
- last period;
- result references safe for follow-up;
- pending clarification slots;
- locale;
- bounded user preferences explicitly allowed for memory.

Rules:

- memory stores references/summaries, not authorization decisions;
- every reused reference is re-authorized on the current turn;
- never store provider API keys, access tokens, hidden prompts, raw unrelated customer data or unrestricted tool payloads;
- retention follows Assistant runtime policy;
- deletion/clear uses the existing conversation lifecycle.

### 5.5 Provider abstraction

#2210 owns implementation of:

**AssistantProviderGatewayInterface**
- capabilities(): AssistantProviderCapabilities
- complete(AssistantProviderRequest): AssistantProviderResult
- health(): AssistantProviderHealth

**AssistantProviderCapabilities**
- chat supported;
- structured JSON supported;
- tool-call contract supported;
- context/token limits;
- timeout/routing limits;
- model/provider identifier;
- health/reachability;
- agent-capable boolean distinct from merely reachable.

**AssistantProviderRequest**
- system/task instructions;
- minimized user content/context;
- allowed tool definitions when tool selection is needed;
- response schema;
- temperature/token/time budget;
- correlation id;
- no database credentials/secrets/business-write authority.

**AssistantProviderResult**
- normalized text/structured payload;
- proposed tool calls, never executed implicitly;
- model/provider metadata;
- finish/failure reason;
- usage/latency telemetry without secrets.

The current AssistantExternalAiClient is the implementation lineage to evolve. Current Qwen think-tag/fenced-JSON normalization and public-endpoint safety are retained unless superseded by a stricter compatible gateway.

### 5.6 Approvals and future writes

Until #2224 merges, all registered tools remain READ.

#2224 implements a single approval lifecycle:

~~~text
READ/analysis
   -> DRAFT proposed action
   -> server computes exact effect/targets
   -> user sees localized preview
   -> explicit approval
   -> re-authenticate/re-authorize/revalidate
   -> idempotent EXECUTE
   -> immutable audit/result
~~~

**AssistantApprovalDraft** must include:

- approval id;
- action type/version;
- exact target references;
- exact proposed values/effects;
- required permission(s);
- actor;
- tenant/store/channel;
- expiry;
- idempotency key;
- localized human preview;
- risk/warning data;
- evidence/reason references.

Provider text such as "approved", "do it" or a remembered prior approval is never an approval token. Execution must require the server-owned approval record and explicit user confirmation.

### 5.7 Failure and fallback

**AssistantFailure** has a stable code and safe localized message. Minimum architecture-level classes:

- PROVIDER_DISABLED
- PROVIDER_UNAVAILABLE
- PROVIDER_INCAPABLE
- PROVIDER_MALFORMED
- TOOL_NOT_REGISTERED
- TOOL_ARGUMENT_INVALID
- TOOL_UNAVAILABLE
- SCOPE_DENIED
- CONSENT_REQUIRED
- DATA_UNAVAILABLE
- DATA_PARTIAL
- APPROVAL_REQUIRED
- APPROVAL_EXPIRED
- ACTION_CONFLICT
- EXECUTION_BUDGET_EXCEEDED

Fallback rules:

1. Provider routing/planning failure -> use deterministic Assistant behavior when the request is supported there.
2. Provider unavailable with a valid deterministic result -> return the deterministic result, not a 5xx.
3. Business/read-model failure -> do not let the provider invent the missing fact; return explicit unavailable/partial state.
4. Authorization/consent denial -> no provider retry with broader scope.
5. Malformed proposed tool call -> reject before execution; bounded retry may occur only within #2210/#2215 policy.
6. Assistant subsystem disabled -> normal FOODEX modules remain fully usable.
7. Future action failure -> never silently retry a non-idempotent mutation.

## 6. Deterministic analytics contract

#2212, #2213 and #2218 together own deterministic analytical truth.

Provider responsibilities:
- understand user language;
- choose among allowed analytical intents/tools;
- explain deterministic outputs;
- compare already-computed values;
- summarize risk/opportunity;
- ask clarification when scope is ambiguous.

Software responsibilities:
- all totals and counts;
- percentages and rates;
- margin and revenue;
- date/window normalization in Asia/Kuwait where required by the domain contract;
- stock/coverage/velocity;
- forecasts/reorder calculations;
- anomaly thresholds;
- sell-in/sell-out joins and coverage;
- currency/unit normalization;
- permission and consent decisions.

## 7. Existing tests are regression gates

The following current test families remain mandatory compatibility evidence while the program evolves:

- AssistantFoundationTest: fail-closed defaults, isolated schema/models, permission matrix and typed registry duplication guard.
- AssistantConversationEngineTest: AR/EN/mixed intent parsing, entity/time parsing, multi-turn resolution, clarification, small-talk and unsupported fallback.
- AssistantBusinessToolsTest: exact sales, order/store/customer/product scope and deterministic business values.
- AssistantOperationsToolsTest: late/cancelled orders, drivers, inventory permission/scope and deterministic daily brief.
- AssistantApiIntegrationTest: disabled/unauthorized behavior, persistence, language, forged page-context rejection and rate limiting.
- AssistantSettingsTest: runtime/provider settings, encrypted secret storage, Qwen normalization, provider probe and read-only enforcement.
- AssistantV1AcceptanceTest: authorized page context, no external-AI dependency, bounded read-only services and safe deep-link allowlist.

Downstream work adds tests; it does not delete these gates merely because a provider or orchestrator becomes more capable.

## 8. OpenAPI decision for #2209

The canonical public API is /api/v1 and docs/api/openapi.yaml is authoritative for it.

The current Assistant conversation/settings surfaces are authenticated Management Dashboard routes in backend/routes/web.php under /admin/assistant and /admin/settings/assistant. #2209 introduces **no new HTTP endpoint and no change to an existing /api/v1 request/response schema**, so there is intentionally no OpenAPI file delta in this issue.

Any downstream issue that adds or changes a versioned /api/v1 AI/analytics/consent/action endpoint must update docs/api/openapi.yaml in that same PR. Moving the existing Management Assistant to a second API stack merely to satisfy OpenAPI is forbidden.

## 9. Downstream issue ownership and file fences

#2209 defines boundaries only. The following implementation ownership is reserved:

| Issue | Primary ownership | Shared-file rule |
| --- | --- | --- |
| #2210 AI-02 Provider/model gateway | App\Services AssistantExternalAiClient/ProviderSettings lineage; provider DTO/contracts under Domain/Assistant | Preserve settings compatibility and Qwen normalization. Do not modify tool/domain calculations. |
| #2211 AI-03 Identity/RBAC/tenant/consent | principal context, consent policy, tenant/channel authorization adapters | Own policy/consent layer; coordinate any shared permission config changes. |
| #2212 AI-04 Semantic metrics | canonical metric definitions/services/DTOs | Own metric meanings; no provider arithmetic. |
| #2213 AI-05 Analytics read model | analytical projections/fact services | Consume #2212 metrics; do not own provider/orchestrator. |
| #2214 AI-06 Typed read tools | AssistantToolInterface/Registry extensions, tool definitions/schemas | Preserve existing tool keys and one registry. |
| #2215 AI-07 Orchestrator | AgentOrchestrator + AssistantBrain compatibility integration + turn execution | Own multi-tool planning/execution flow; do not bypass #2211/#2214. |
| #2216 AI-08 Context/memory | Assistant conversation state/context DTOs and retention-safe memory | Reuse existing conversation/message/state storage. |
| #2217 AI-09 Retail sell-out ingestion | approved connector/import ingestion and source provenance | Does not grant wholesale read permission; #2211 policy remains mandatory. |
| #2218 AI-10 Analytics engine | deterministic comparison/diagnostic engine | Uses semantic/read-model facts; no LLM arithmetic. |
| #2219 AI-11 Wholesale intelligence | wholesale analyses/tools | Consume shared metrics/engine; no duplicate metric definitions. |
| #2220 AI-12 Retailer 360 | retailer sell-in + permitted sell-out composition | Must keep datasets separately labeled and consent-gated. |
| #2221 AI-13 Forecast/replenishment | deterministic forecast/reorder services | Provider only explains/recommends based on computed output. |
| #2222 AI-14 Anomaly/opportunity | deterministic detection services | Threshold/model provenance must be evidence-bearing. |
| #2223 AI-15 Knowledge/RAG | approved knowledge indexing/retrieval | RAG cannot expand data/RBAC scope. |
| #2224 AI-16 Safe actions | approval records, execution policy, audit/idempotency | Only issue allowed to cross read-only boundary for AI actions. |
| #2225 AI-17 Assistant UX | existing Assistant drawer/page-aware UX and localization | No second Assistant application. |
| #2226 AI-18 Proactive intelligence | briefs/notifications using authorized deterministic facts | Must not bypass consent or user scope because execution is scheduled. |
| #2228 AI-19 Observability | traces/health/telemetry/redaction | No secret/prompt/customer leakage in telemetry. |
| #2229 AI-20 Security | prompt-injection/exfiltration/data-boundary controls | Security controls wrap the same registry/orchestrator. |
| #2230 AI-21 Evaluation | golden questions/permission/numeric/safety regression harness | Evaluates deterministic ground truth and agent behavior separately. |
| #2231 AI-22 Performance | budgets, caching, resilience/degraded mode | Cache keys must include complete authority/scope. |
| #2232 AI-23 Acceptance | final production acceptance/release evidence | No new architecture unless a discovered blocker is fixed in its owning issue. |

Shared core files such as backend/app/Providers/AppServiceProvider.php, backend/config/permissions.php, backend/routes/web.php and the existing Assistant controller are single-owner-at-a-time files under AGENTS.md. A downstream worker must inspect active ownership before editing them.

## 10. Dependency order

Normative order:

~~~text
#2209 Architecture
  +-- #2210 Provider
  +-- #2211 Identity/RBAC/Consent
  +-- #2212 Semantic Metrics
          |
          v
       #2213 Read Model
          |
          v
       #2214 Typed Tools
          |
          v
       #2215 Orchestrator ---- #2216 Context/Memory
          |
          +-- #2218 Analytics Engine
          +-- #2223 RAG
          +-- #2228 Observability
          +-- #2229 Security
          |
          +-- #2217 Sell-Out Ingestion
          +-- #2219 Wholesale Intelligence
          +-- #2220 Retailer 360
          +-- #2221 Forecast/Replenishment
          +-- #2222 Anomaly/Opportunity
          |
          v
       #2224 Approval Actions
       #2225 Assistant UX
       #2226 Proactive Intelligence
          |
          v
       #2230 Evaluations
       #2231 Performance/Resilience
          |
          v
       #2232 Production Acceptance
~~~

Parallel work is permitted only when dependencies are satisfied and file ownership is disjoint.

## 11. Architecture review evidence for #2209

Reviewed against:

- root AGENTS.md worker/branch/authority rules;
- docs/architecture/SYSTEM_ARCHITECTURE.md;
- docs/architecture/ROLE_ARCHITECTURE.md;
- docs/architecture/MULTI_TENANT_ARCHITECTURE.md;
- docs/architecture/RBAC_ADMINISTRATION.md;
- docs/architecture/FOODEX_ASSISTANT_V1_CHARTER.md;
- docs/api/openapi.yaml public API boundary;
- current Assistant contracts/controller/settings/tool registry;
- current deterministic Assistant test families;
- current OpenAI-compatible provider configuration;
- merged Ollama/Qwen routing normalization at a4fc5cb2394a62a35fe60847150b4529d4886ba5.

Result:

| Acceptance criterion | Architecture result |
| --- | --- |
| No competing Assistant | PASS: all future runtime work extends existing Assistant routes/state/brain/registry/provider lineage. |
| Authoritative interfaces/boundaries/dependency order named | PASS: Sections 3-10. |
| Read-only/tenant/store/channel default preserved | PASS: Sections 1, 4 and 5.6. |
| Core FOODEX works with AI/provider disabled | PASS by invariant and provider-fallback contract; current runtime tests remain regression gates. |
| Durable docs/OpenAPI where affected | PASS: this architecture is authoritative; .ai/system architecture are linked in #2209. OpenAPI reviewed and intentionally unchanged because no /api/v1 surface changes. |

## 12. Change rule

Any future change that creates a second Assistant stack, gives a provider direct data authority, merges sell-in and sell-out semantics, weakens server re-authorization, bypasses approval for writes, or makes FOODEX core depend on provider availability conflicts with this contract and requires an explicit architecture decision before implementation.
