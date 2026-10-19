# FOODEX Agentic AI — Production Operations and Rollout Runbook

Issue: **#2232 / AI-23**

This runbook operates the existing FOODEX Assistant/Ollama/OpenAI-compatible path. Laravel/domain services remain authoritative. Do not introduce a second chatbot runtime, direct model SQL, model credentials or a standalone AI write path.

## 1. Record the candidate

Before any deployment or pilot, record the real:

- candidate Git SHA and FOODEX version;
- target environment;
- database/application backup identifiers;
- migration status before change;
- operator and rollback decision owner.

Do not invent or pre-fill production values in GitHub evidence.

## 2. Safe deployment posture

Deploy code and migrations with the Assistant globally disabled first:

```env
ASSISTANT_ENABLED=false
ASSISTANT_AI_ENABLED=false
ASSISTANT_AI_PROVIDER_ENABLED=false
ASSISTANT_AI_TOOLS_ENABLED=false
ASSISTANT_AI_WRITE_ACTIONS_ENABLED=false
```

Run the repository-approved Laravel install/upgrade sequence for the environment, then verify migration status and ordinary FOODEX management/commerce health before enabling any AI capability.

## 3. Configuration validation

Before pilot activation verify:

- the provider URL is an approved OpenAI-compatible/Ollama endpoint;
- model capability/health validation succeeds;
- API credentials, if any, are stored only through the approved secret path;
- retention, timeout, retries, circuit-breaker and execution budgets match the approved environment;
- observability persistence is available or fails open without blocking commerce;
- write actions remain OFF until the approval-action gate has passed.

## 4. Staged pilot

### Stage 0 — deployed, disabled

All AI controls OFF. Acceptance metric: ordinary FOODEX journeys stay healthy and no Assistant entry/function becomes available accidentally.

### Stage 1 — internal read pilot

Enable global Assistant and provider/tools only for approved internal roles. Keep write actions OFF.

Acceptance metrics:

- zero cross-tenant/store/consent leakage;
- zero secret/prompt exposure in UI, trace or export;
- deterministic numeric parity with authoritative fixtures/evidence;
- provider fallback and recovery behave within documented latency/error budgets;
- no unresolved AI P0/P1 defect.

### Stage 2 — scoped business pilot

Expand only to approved wholesale/retailer cohorts. Validate AR/EN, evidence freshness, sell-in/sell-out labels, RAG citations, proactive alerts and trace health. Permission/consent changes must take effect immediately.

### Stage 3 — approval-governed actions

Only after #2224 evidence is integrated may `ASSISTANT_AI_WRITE_ACTIONS_ENABLED=true` be considered. Each action still requires a current server-owned approval record, explicit confirmation, re-authorization/revalidation, idempotency/conflict checks and audit.

## 5. Stop/rollback thresholds

Immediately disable the smallest relevant capability if any of these occur:

- suspected tenant/store/retailer data leakage;
- secret or hidden-instruction exposure;
- write without valid approval;
- authoritative numeric mismatch;
- provider/tool failure that affects core FOODEX commerce;
- sustained latency/error rate above the accepted #2231 budget;
- unresolved P0/P1 security or correctness defect.

Preferred containment order:

1. `ASSISTANT_AI_WRITE_ACTIONS_ENABLED=false`
2. `ASSISTANT_AI_TOOLS_ENABLED=false`
3. `ASSISTANT_AI_PROVIDER_ENABLED=false` and/or `ASSISTANT_AI_ENABLED=false`
4. `ASSISTANT_ENABLED=false`

Refresh Laravel configuration using the environment's approved procedure after a setting change.

Do **not** perform a destructive migration rollback merely to disable AI. Preserve Assistant/trace/audit data needed for diagnosis unless the approved recovery plan explicitly requires database restoration.

## 6. Recovery drill

After fixing the cause:

1. keep Assistant/provider/actions disabled;
2. verify ordinary FOODEX health;
3. verify migrations/configuration and provider health;
4. run the integrated #2232 regression/evaluation/security/performance gates;
5. re-enable one stage at a time;
6. confirm deterministic fallback during a controlled provider outage;
7. confirm provider recovery on the same governed gateway;
8. record the real result and exact deployed SHA.

## 7. Observability checks

Operators may use only redacted, role-authorized diagnostics. Confirm correlation IDs across turns/provider/tools, provider and tool latency/errors, fallback rate, model identity/capability, retention pruning and safe diagnostics export. Never use trace storage as a shadow warehouse for customer prompts, tool payloads or secrets.

## 8. Production evidence boundary

Repository acceptance can prove code behavior on production-like fixtures/services. Real production rollout evidence—backup ids, live migration output, live HTTPS/session smoke, exact production provider endpoint health and the human enable/rollback decision—must come from the actual deployment. If that rollout has not happened, record it as external required work rather than fabricating evidence.
