# FOODEX Agent Orchestrator Contract

Issue: #2215  
Parent: #2208  
Depends on: #2210 provider gateway, #2214 typed tool registry  
Version: 1.0

## Responsibility boundary

`AssistantAgentOrchestrator` upgrades the existing FOODEX Assistant into a bounded multi-tool read agent. It does not replace the Assistant, provider gateway, tool registry, semantic layer, analytics read model, RBAC or audit services.

The provider may **propose** typed tool calls only. A proposal has no authority. Every call is normalized back to an allowlisted `AssistantToolRegistry` key and executed through `AssistantToolExecutor`, which re-applies principal, permission, tenant/store/channel and retailer sell-out consent checks.

Unknown tools, raw SQL fields, scope injection and malformed arguments never reach business services.

## Bounded loop

The orchestrator has explicit server-owned budgets:

- planning steps: default 2, hard maximum 4 (the current implementation uses one planning response per turn);
- tool calls: default 4, hard maximum 8;
- provider/result context: default 64 KiB;
- wall-clock budget: default 12 seconds, hard maximum 30 seconds;
- planning output: default 384 tokens;
- grounded synthesis output: default 640 tokens.

Budgets are configurable through `assistant.agent.*` but bounded again in code. Duplicate tool/argument signatures are not executed twice. Result payloads are depth/list/string bounded before they are returned to the model.

The current execution model accepts multiple independent tool proposals in one planning step and executes them deterministically in sequence. This preserves per-call reauthorization and audit ordering. Later parallelization is allowed only if those same server-side guarantees remain intact.

## Planning and evidence

Planning receives only:

- the user request;
- the provider-facing typed tool definitions;
- minimized page/context hints;
- minimized conversation state;
- already returned numbered evidence;
- remaining budget counters.

Planner text is never stored as reasoning and is never exposed as chain-of-thought. The only durable orchestration state is operational metadata such as tool keys, counts, correlation id, references and the final grounded answer.

Tool results become server-numbered evidence such as `ev-call-1`. Failed non-authorization reads are represented as unavailable evidence and produce a partial answer. Authorization, consent and schema failures remain fail-closed and are propagated instead of being converted into model-readable errors.

## Grounded synthesis

Final synthesis runs through the existing `AssistantProviderGatewayInterface` with the `grounded_synthesis` use case and no tools enabled. The response contract contains only:

- `answer`;
- `partial`;
- `uncertainty`;
- evidence ids.

The model is instructed to use only server evidence and never invent missing business values. If synthesis is unavailable or malformed, FOODEX returns a localized partial fallback while keeping the structured server evidence available.

## Compatibility and degraded mode

If the provider is disabled, unavailable, incapable, or returns no tool calls, the orchestrator returns control to the existing `AssistantBrainInterface` flow. Therefore deterministic greetings, single-intent routing and normal FOODEX operation continue to work without AI.

No public `/api/v1` contract changes in #2215; orchestration remains inside the existing Assistant endpoints.

## Audit and privacy

Each executed tool still emits the existing `assistant.tool.read` audit event with the same root correlation id. The agent response reports `model_sql_allowed=false` and `chain_of_thought_stored=false`.

Provider planning/synthesis receives no database credentials, API keys, permission secrets or model-authored SQL surface.
