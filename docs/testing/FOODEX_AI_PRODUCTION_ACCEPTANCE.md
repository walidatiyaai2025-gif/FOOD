# FOODEX Agentic AI — Production Acceptance

Issue: **#2232 / AI-23**  
Program: **AI-PROGRAM / #2208**  
Canonical branch: `feat/2232-ai-production-acceptance`

This is the final repository convergence gate for the Agentic AI program. It certifies only evidence produced by the integrated FOODEX source lineage. It does not infer completion from child Issue state, and it never fabricates production deployment facts.

## Authority and acceptance rule

Live GitHub state and exact-head CI are authoritative. The machine-readable gate inventory is `docs/execution/FOODEX_AI_PRODUCTION_ACCEPTANCE_MATRIX.json`.

A gate may move to **PASS** only when its evidence is reproducible on the same integrated source that will merge to `main`. A child PR being closed, an Issue being closed, or a generic CI run being green is not by itself final production acceptance.

The final PR must prove:

- all declared dependencies (#2224, #2225, #2226, #2228, #2229, #2230 and #2231) are present in the integrated source;
- required repository CI, AI evaluations and security regressions are green on the exact PR head;
- wholesale sell-in and retailer sell-out remain explicitly distinct data classes;
- retailer sell-out consent, tenant/store/channel scope and permissions are re-authorized server-side;
- multi-tool analysis, governed RAG, proactive findings and action approvals stay inside the existing FOODEX Assistant stack;
- model/provider output never becomes business-number, authorization or write authority;
- provider outage/recovery degrades safely to deterministic/non-AI behavior;
- core FOODEX remains functional when Assistant/provider/tools/write actions are disabled;
- install/upgrade, migrations, observability, rollback and pilot rollout are operationally documented and exercised by repository-supported acceptance where applicable.

## Production-like journey matrix

| Journey | Required proof |
| --- | --- |
| Wholesale owner | Authorized B2B sell-in facts, deterministic comparisons/rankings, multi-tool plan, evidence/freshness, RAG citations, proactive finding and approval-governed action. |
| Retailer manager | Own-store retail facts only; sell-out consent grant/revoke semantics; no conversion of wholesale purchase/sell-in into sell-out. |
| Platform admin | Provider/model health, redacted traces, tool availability, retention/export authorization and independent kill switches. |
| Unauthorized user | No Assistant access or cross-scope data/tool/action execution; forged context, revoked consent and permission loss fail closed. |

Each journey must be represented by automated evidence on the final integrated head. UI/runtime evidence is required where the underlying dependency introduced or materially changed a screen.

## Mandatory cross-cutting drills

### Authorization and data semantics

The final source must retain server-created principal context, per-call permission/scope checks, retailer sell-out consent and deterministic semantic/read-model calculations. The model may interpret or narrate; it may not relabel SELL_IN as SELL_OUT or calculate authoritative business numbers.

### Provider outage and recovery

Acceptance must exercise the real FOODEX OpenAI-compatible provider gateway path, including the Ollama-compatible request/response contract. Required outcomes are bounded timeout/circuit behavior, no secret leakage, no invented business facts, deterministic fallback where supported, and successful recovery without restarting or weakening core commerce controls.

### Action safety

Every supported write remains `DRAFT -> explicit approval -> re-authorize/revalidate -> idempotent EXECUTE -> audit`. Provider text, remembered approval, stale payloads or changed context never count as authorization.

### Kill switches

The final security contract supplies independent controls for:

- `ASSISTANT_ENABLED` — global Assistant availability;
- `ASSISTANT_AI_ENABLED` / provider configuration — optional model path;
- `ASSISTANT_AI_PROVIDER_ENABLED` — provider security fence;
- `ASSISTANT_AI_TOOLS_ENABLED` — typed tool execution fence;
- `ASSISTANT_AI_WRITE_ACTIONS_ENABLED` — write execution fence, default OFF.

The final drill must demonstrate that disabling AI/provider capability does not break ordinary FOODEX commerce.

## Install, upgrade and rollback evidence

Repository CI may prove disposable install/upgrade/migration/recovery behavior. The operator runbook defines production procedure and rollback. A repository test may not invent a production database backup id, cPanel path, live migration result, HTTPS result or human activation decision.

If production rollout is not explicitly authorized, those items remain **EXTERNAL_REQUIRED**, not falsely marked PASS.

## Final merge checklist

Before merge, the #2232 worker must independently re-read the Issue, acceptance matrix, integrated source, dependency evidence, exact-head CI and review threads. The PR is not mergeable by policy while any repository-owned gate is PENDING/BLOCKED/FAIL, any required dependency is absent, any AI P0/P1 defect remains unresolved, or any required review thread is unresolved.

The final Issue/PR evidence comment must record the exact head SHA and identify every machine gate used for the merge decision.
