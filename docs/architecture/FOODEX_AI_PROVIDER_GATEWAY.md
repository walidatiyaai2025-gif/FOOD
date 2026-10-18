# FOODEX AI Provider Gateway Contract

Issue: #2210  
Parent: #2208  
Runtime: existing FOODEX Assistant / OpenAI-compatible / Ollama path

## Purpose

The provider gateway extends the existing FOODEX Assistant transport. It does not create a second chatbot stack and it never becomes an authorization or business-calculation boundary.

Laravel domain services remain authoritative. The provider receives only the bounded prompt/model contract required for the current Assistant step. It never receives database credentials and it cannot execute SQL.

## Model registry

`AssistantModelRegistry` exposes one ordered registry:

1. the saved active model;
2. zero or more configured fallback models;
3. permitted use cases: `intent_routing`, `agent_planning`, and `grounded_synthesis`;
4. configured temperature, max-output-token budget, and context-window budget.

The active model remains compatible with the 1.0.91 setting format. New policy keys are optional and fall back to `backend/config/assistant.php` defaults, so existing saved provider settings require no migration.

Environment policy keys:

- `ASSISTANT_AI_FALLBACK_MODELS`
- `ASSISTANT_AI_TEMPERATURE`
- `ASSISTANT_AI_MAX_TOKENS`
- `ASSISTANT_AI_CONTEXT_WINDOW_TOKENS`
- `ASSISTANT_AI_RETRY_ATTEMPTS`
- `ASSISTANT_AI_CIRCUIT_BREAKER_FAILURES`
- `ASSISTANT_AI_CIRCUIT_BREAKER_SECONDS`

## Typed provider abstraction

#2210 implements the provider abstraction reserved by the parent architecture:

- `AssistantProviderGatewayInterface::capabilities(): AssistantProviderCapabilities`
- `AssistantProviderGatewayInterface::complete(AssistantProviderRequest): AssistantProviderResult`
- `AssistantProviderGatewayInterface::health(): AssistantProviderHealth`

`AssistantExternalAiClient` implements that interface and is bound to it in Laravel's container. The request contract carries server-owned system/task instructions, minimized content/context, allowlisted tool definitions, an optional response schema, token/temperature/timeout budgets, correlation id and an explicit registry use case. Provider results normalize text/structured payloads, proposed tool calls, finish/failure reason, usage, latency and safe model metadata. Tool calls are proposals only and are never executed by this gateway.

Provider failures use the stable architecture taxonomy `PROVIDER_DISABLED`, `PROVIDER_UNAVAILABLE`, `PROVIDER_INCAPABLE` and `PROVIDER_MALFORMED`, allowing the current deterministic Assistant and the future orchestrator to fail closed consistently.

## Capability probe

The admin capability probe separates transport reachability from agent readiness.

It checks:

- reachable OpenAI-compatible Chat Completions endpoint;
- exact FOODEX chat sentinel contract;
- structured JSON contract;
- OpenAI-compatible function/tool calling contract;
- configured context-window and output-token budgets;
- request timeout and observed probe latency.

A model can be reachable while `agent_capable=false`. Agent capability requires chat, structured JSON, tool calls, and the minimum configured budget contract.

## Resilience

Runtime routing uses the model registry in order.

For each model:

- timeout is bounded by the saved provider policy;
- retries are bounded and apply only to transport failures, HTTP 429, and HTTP 5xx;
- ordinary HTTP 4xx responses are not retried;
- an exhausted model failure increments a per-endpoint/per-model circuit;
- the circuit opens after the configured threshold and automatically cools down;
- the next permitted fallback model is attempted;
- if all models fail or return malformed routing output, the external AI route returns `null` and the existing deterministic FOODEX path remains authoritative.

Provider failure must never disable normal FOODEX commerce.

## Telemetry and secret handling

Provider telemetry records only:

- provider kind;
- redacted endpoint (scheme/host/port/path only; no query, fragment, userinfo, headers, or body);
- model;
- outcome/status;
- latency;
- attempt number;
- whether the model is a fallback.

API keys, prompt bodies, tool arguments, and URL query secrets are never added to provider telemetry.

## Authorization boundary

Provider/model routing does not change authorization.

Every current and future business tool remains responsible for server-side:

- user/role authorization;
- tenant/store/channel scope;
- retailer sell-out sharing/consent;
- input schema validation;
- deterministic business calculations;
- audit requirements.

Malformed model output is treated as untrusted and cannot create new intents, tools, permissions, or business facts.

## OpenAPI impact

#2210 changes internal provider/admin behavior only. No public `/api/v1` request/response contract is introduced or changed by this issue, so no OpenAPI schema change is required.
