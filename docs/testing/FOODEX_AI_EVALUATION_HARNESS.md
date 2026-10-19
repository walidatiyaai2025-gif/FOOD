# FOODEX AI Evaluation Harness

Issue: **#2230 / AI-21**  
Parent: **#2208**  
Runtime: existing FOODEX Assistant / provider gateway / typed tool registry

## Purpose

AI quality is a release gate, not a wording review. The evaluation harness gives Required CI a reproducible, versioned corpus that fails when factual correctness, permissions, grounding, scope or action safety regress.

The harness does not create a second Assistant stack and it never becomes a business authority. Laravel domain services and typed Assistant tools remain authoritative; the evaluator only checks observed results against their expected contract.

## Versioned assets

- `backend/tests/Fixtures/AssistantEvaluation/golden-v1.json` — AR/EN golden business questions and expected deterministic properties.
- `backend/tests/Fixtures/AssistantEvaluation/provider-baselines-v1.json` — supported provider/capability profiles and language thresholds.
- `backend/tests/Support/AssistantEvaluation/GoldenEvaluationRunner.php` — deterministic evaluator.
- `backend/tests/Unit/AssistantEvaluationHarnessTest.php` — CI gate and anti-regression tests.

Changing a schema requires a new version. Do not silently rewrite a historical baseline to make a regression green.

## Golden domains

The v1 corpus covers:

- wholesale sell-in analysis;
- retailer actual sell-out with explicit source semantics;
- inventory alerts;
- order operations;
- approved policy/RAG retrieval;
- permission and cross-scope denials;
- insufficient-data behavior;
- approval-governed action safety;
- Arabic and English.

Sell-in must never be relabelled as sell-out.

## Hard deterministic gates

A case can require any combination of:

- exact completion/denial/degraded status;
- required and forbidden typed tools;
- numeric parity with explicit tolerance;
- exact server-owned scope;
- required and forbidden semantic values;
- minimum evidence/provenance;
- refusal behavior;
- insufficient-data state with zero invented facts;
- action preview requiring explicit approval and proving no execution occurred.

Any hard-gate failure fails CI regardless of how fluent the answer sounds.

## Language quality is separate

Language quality is a profile-level threshold, not a source of business truth. Wording can vary while facts, scope, permissions, evidence and safety may not.

The evaluator therefore returns both:

- `deterministic_passed`;
- `language_passed`.

The combined result requires both, while diagnostics keep them separate so a language regression is never confused with a numeric or authorization defect.

## Provider/model profiles

v1 defines:

- `openai_compatible_agent_v1` — chat + structured JSON + tool-calling agent capability;
- `deterministic_fallback_v1` — provider-disabled/incapable fallback path.

Every supported profile has a `zero_hard_failures` deterministic policy. A provider or model change is not acceptable if it requires weakening factual, permission, grounding or safety expectations.

A new provider/model capability shape must add or version its profile and run the same golden corpus before release.

## CI integration

The harness lives under the normal backend PHPUnit suites, so every Required CI backend test run executes the golden gate. Provider, orchestrator, tool, security, RAG or Assistant changes therefore cannot bypass the evaluator by using a different test command.

The CI corpus is deliberately deterministic and network-free. Production/provider qualification may capture additional model observations, but external availability is never required for repository correctness and core FOODEX remains functional when AI/provider is disabled.

## Failure interpretation

- numeric mismatch: fix the authoritative data/tool path or the consumer; do not loosen tolerance to hide a defect;
- wrong/forbidden tool: fix planning/routing or registry capability;
- missing evidence: fail grounded-answer quality;
- scope/refusal failure: treat as authorization/privacy defect;
- hallucinated unavailable data: fail;
- action executed or approval bypassed: fail;
- language-only threshold regression: address presentation/model quality without changing deterministic expectations.

## OpenAPI impact

AI-21 adds only repository testing/evaluation contracts. It introduces no public `/api/v1` endpoint or schema change, so `docs/api/openapi.yaml` is unchanged.
