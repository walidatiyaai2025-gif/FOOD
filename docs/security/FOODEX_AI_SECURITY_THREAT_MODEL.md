# FOODEX AI Security Threat Model and Guardrail Contract

Status: **Authoritative implementation contract**  
Program: **AI-PROGRAM / #2208**  
Issue: **#2229 / AI-20 / P0**  
Parent contracts: `docs/architecture/FOODEX_AI_AGENT_ARCHITECTURE.md`, `docs/architecture/FOODEX_AI_SECURITY_CONTRACT.md`

## 1. Security objective

FOODEX keeps the existing Management Assistant/Ollama/OpenAI-compatible lineage and treats every model as an untrusted reasoning adapter. Laravel/domain services remain authoritative for identity, RBAC, tenant/store/channel scope, retailer sell-out consent, calculations, persistence and action execution.

The security boundary must remain effective when the provider is malicious, compromised, prompt-injected, unavailable or disabled.

## 2. Trust boundaries

Trusted server authority:

- authenticated FOODEX user and current active roles/permissions;
- server-derived tenant/store/channel scope;
- `AssistantToolRegistry` typed allowlist and `AssistantToolExecutor`;
- retailer sell-out consent policy;
- deterministic domain/read-model calculations;
- Laravel configuration, encrypted provider credentials and audit storage.

Untrusted or non-authoritative inputs:

- user prompts;
- provider/model text and tool proposals;
- retrieved RAG/document text;
- page/client identifiers;
- conversation memory;
- provider tool arguments;
- external knowledge sources until approved/indexed;
- any approval claim authored by a model.

No untrusted input may create authority.

## 3. Threats and controls

### Direct prompt injection

Examples include requests to ignore prior instructions, reveal hidden prompts, disclose credentials, bypass authorization or invoke tools outside the allowlist.

Controls:

- provider-bound user content is scanned before leaving FOODEX;
- known direct and encoded instruction-bypass patterns fail closed;
- a blocked prompt never reaches the provider;
- deterministic Assistant behavior remains available instead of breaking commerce;
- security event: `assistant.security.prompt_blocked`.

### Indirect prompt injection / RAG poisoning

Retrieved document text is data, never executable instruction. The knowledge layer already marks retrieved excerpts as untrusted; the central result guard additionally neutralizes instruction-like or encoded malicious lines before provider synthesis.

Controls:

- document instructions cannot select or authorize tools;
- unsafe lines are replaced with `[UNTRUSTED_INSTRUCTION_REMOVED]`;
- factual neighboring text can remain usable;
- RAG scope continues to be store/global filtered by the knowledge index;
- server evidence, not document instructions, is the only synthesis authority.

### Tool argument injection and tool abuse

Model-proposed tools are proposals only.

Controls:

- only registered typed tools are executable;
- `AssistantToolDefinition` validates schema and prevents server-scope override;
- the AI security guard rejects credential/auth/system-prompt/SQL authority fields and instruction-injected argument strings;
- every tool execution re-resolves current identity/RBAC/store/channel/consent;
- tool kill switch blocks all AI tool execution;
- non-READ execution is blocked unless the explicit write-action kill switch is enabled;
- security events: `assistant.security.tool_argument_blocked` and `assistant.security.tool_blocked`.

Approval text from a model is never approval. Future write actions must still implement the independent draft -> human approval -> re-authorization -> idempotent execution lifecycle owned by its write-action issue.

### Cross-tenant / cross-store exfiltration

Controls:

- page ids, model arguments and conversation state never grant store access;
- `AiPrincipalContextResolver` and `AssistantToolExecutor` re-authorize every call;
- retailer sell-out grants remain exact and revocable;
- cross-retailer aggregates require consent for every retailer;
- generic denial avoids hidden-id disclosure;
- result sanitization does not weaken underlying domain authorization.

### Secret and hidden-configuration exposure

Controls:

- API keys remain encrypted/server-side and are never tool authority;
- provider output is filtered for configured API-key material, bearer tokens and common secret patterns;
- exact echoes of server system/task instructions are removed;
- tool data/cards/references remove sensitive keys recursively;
- audit metadata reuses the existing redaction layer;
- security event: `assistant.security.provider_output_redacted` or `assistant.security.result_redacted`.

### Excessive data return

Controls:

- existing per-tool row/time/result budgets remain authoritative;
- central security filtering caps collection size, nesting depth and string size;
- orchestrator total/per-tool byte budgets remain enforced;
- sensitive fields are removed before results become model evidence.

### Cohort privacy and differencing attacks

Benchmark/peer-comparison tools that return protected cohort analytics must publish:

```text
_privacy.cohort_size
_privacy.cohort_key
_privacy.metric_key
_privacy.member_ids   # optional server-side list; stripped if sensitive policy requires
```

Default protections:

- minimum cohort: 5;
- below minimum returns `COHORT_TOO_SMALL` with no metric payload;
- repeated near-identical cohort queries are cached per actor/tool/cohort/metric;
- a cohort-size/member change below the configured delta is blocked as `DIFFERENCING_RISK`;
- default minimum delta: 2;
- default comparison window: 900 seconds;
- security event: `assistant.security.cohort_blocked`.

Business tools remain responsible for emitting the privacy metadata for any protected benchmark. Absence of such metadata is not permission to construct a privacy-sensitive cohort outside the deterministic service.

## 4. Kill switches

The switches are independent so incident response can disable only the unsafe layer:

| Layer | Authority | Default | Effect |
| --- | --- | --- | --- |
| Entire Assistant | existing `ASSISTANT_ENABLED` / dashboard `assistant_enabled` | existing deployment policy | disables Assistant without affecting FOODEX commerce |
| External provider config | existing `ASSISTANT_AI_ENABLED` | false | disables configured external model |
| Provider security switch | `ASSISTANT_AI_PROVIDER_ENABLED` | true | stops outbound model calls and probes |
| AI tools | `ASSISTANT_AI_TOOLS_ENABLED` | true | blocks all Assistant tool execution |
| AI writes | `ASSISTANT_AI_WRITE_ACTIONS_ENABLED` | false | blocks every non-READ Assistant tool |

Turning off AI/provider/tools/writes must not disable ordinary FOODEX domain routes, ERP workflows or deterministic commerce services.

## 5. Security events

Security-specific events are recorded through `AuditLogger` without storing raw malicious prompts or secrets:

- `assistant.security.prompt_blocked`;
- `assistant.security.provider_output_redacted`;
- `assistant.security.tool_blocked`;
- `assistant.security.tool_argument_blocked`;
- `assistant.security.result_redacted`;
- `assistant.security.cohort_blocked`.

Correlation id, tool/use-case, safe reason code and bounded policy metadata may be recorded. Raw credentials, raw prompt bodies and hidden system instructions are not required for security evidence.

## 6. Failure behavior

Security failures fail closed and use bounded reason codes. They do not expose credentials, hidden prompts, internal configuration, hidden tenant identifiers, authorization internals or stack traces.

Provider/prompt security failure may fall back to the existing deterministic Assistant path. Tool authorization/privacy failure never falls back to a wider data source.

## 7. Verification

`AssistantAiSecurityHardeningTest` covers:

- direct injection;
- encoded injection;
- secret/system-instruction output redaction;
- malicious tool arguments;
- malicious RAG text;
- recursive sensitive-field filtering;
- minimum cohort protection;
- differencing attack protection;
- provider/tool/write kill switches;
- security-event audit evidence.

Existing `AssistantSecurityBoundaryTest`, typed-tool and agent-orchestrator tests remain required regression evidence for cross-store isolation, retailer consent, unknown tools, schema enforcement and bounded execution.

## 8. API/OpenAPI impact

#2229 adds no public `/api/v1` endpoint and changes no public HTTP request/response schema. No OpenAPI delta is required.

The guardrail configuration and `_privacy` metadata are internal server/tool contracts. Any future public administration surface for these controls requires its own authenticated API/UI contract.
