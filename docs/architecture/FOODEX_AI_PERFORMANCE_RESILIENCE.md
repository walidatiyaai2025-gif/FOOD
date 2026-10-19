# FOODEX AI Performance and Resilience

Issue: #2231  
Scope: bounded latency, provider capacity isolation, deterministic caching, background-work isolation, cooperative cancellation, and degraded operation for the existing FOODEX Assistant.

## Runtime invariant

Core FOODEX commerce never depends on Ollama or any external AI provider. Provider failure, saturation, cancellation, timeout, or circuit-open state degrades Assistant narration/planning to deterministic FOODEX behavior; it must not block Orders, Inventory, Finance, Customer Service, Van, Driver, Smart Ordering, or other canonical domain flows.

## Latency classes

The Assistant exposes two server-owned latency classes:

| Class | p50 target | p95 target | hard budget |
| --- | ---: | ---: | ---: |
| simple lookup / one tool | 900 ms | 2,500 ms | 6,000 ms |
| multi-tool analysis | 3,500 ms | 8,000 ms | 12,000 ms |

The targets are configurable under `assistant.performance`. The hard budget is always capped by the existing per-turn `AgentExecutionBudget`; the tighter limit wins. Provider timeouts consume the remaining hard budget rather than extending it.

## Provider admission and load shedding

Provider calls use bounded non-waiting admission:

- global slots protect the provider process;
- tenant slots prevent one tenant/store scope from consuming all provider capacity;
- user slots prevent one user from monopolizing a tenant's allowance;
- a saturated scope returns `PROVIDER_OVERLOADED` immediately and the Assistant falls back instead of queueing an interactive request behind unbounded work;
- slot leases expire defensively and are also released explicitly.

Defaults are global 12, tenant 4, user 2. Tune these only from measured provider capacity. They are safety ceilings, not throughput targets.

## Deterministic cache contract

Tool caching is opt-in and occurs only after server-side actor, store/channel, permission, and sell-out consent checks are repeated for the current request.

A tool is cacheable only when all of the following are true:

1. the tool mode is `READ`;
2. its source is in `assistant.performance.cacheable_sources`;
3. its tool definition publishes a positive `freshness.stale_after_minutes`;
4. the configured cache TTL is no longer than that freshness window.

Cache keys include the tool/schema version, actor, business scope, channel, active/permitted stores, effective permissions, retailer-data grants, store/channel request scope, and normalized arguments. Cross-user and cross-tenant reuse is intentionally sacrificed for isolation.

Cached results never reuse old correlation metadata. The current execution wraps the cached deterministic result with the current `correlation_id`, `cache_hit`, and cache TTL metadata and still writes the normal `assistant.tool.read` audit event.

## Cooperative cancellation

The Dashboard contract exposes:

`POST /admin/assistant/conversations/{conversationId}/cancel`

The server resolves conversation ownership before requesting cancellation. The active request correlation id is server-owned and stored only for a bounded TTL. The orchestrator checks cancellation before planning/synthesis boundaries and before each tool execution. Cancellation is cooperative: an already-blocked provider socket is bounded by its HTTP timeout, then the request exits through the deterministic degraded path.

Repeated cancellation is safe. When no request is active, the endpoint returns `cancel_requested=false` without mutating business state. This is an authenticated Management/Dashboard route, not a public `/api/v1` contract; OpenAPI is unchanged by #2231.

## Background queue isolation

Scheduled AI analytics materialization is dispatched as `RefreshAssistantAnalyticsJob` onto the dedicated `assistant-background` queue instead of executing inside the scheduler process.

The job is unique per store set, has one try, a bounded timeout, and a uniqueness window shorter than the 15-minute schedule period. Deployments must run a worker for the configured background queue. Interactive Assistant requests are not placed behind this queue.

Other long-running AI jobs (for example proactive briefs when enabled by their owning feature) must use the same background-queue contract rather than the interactive request path.

## Circuit breaker and outage behavior

The existing provider client retains bounded retries, HTTP/connect timeouts, fallback models, and circuit breaking. #2231 adds admission control around those calls rather than replacing the existing resilience stack.

Expected outage sequence:

1. transport/provider failure is recorded;
2. the circuit opens after the configured threshold;
3. further provider calls are shed without network work while open;
4. deterministic Assistant/core FOODEX paths remain available;
5. after the circuit TTL, a successful call closes/reset the circuit and normal AI behavior resumes.

## Verification

`AssistantPerformanceResilienceTest` covers:

- per-user and per-tenant saturation plus recovery;
- 200 repeated admission operations with measured p50/p95 compared with the documented targets;
- actor/conversation-scoped cancellation;
- cache key isolation and cache opt-in/freshness rules;
- dedicated background queue dispatch;
- separate simple/analysis latency classes.

`AssistantProviderRecoveryTest` covers transport outage, open-circuit load shedding, TTL recovery, and restored provider routing.

Existing Assistant orchestration/provider/security tests remain required because this change deliberately composes with the pre-existing authorization, circuit breaker, deterministic fallback, and audit contracts.
