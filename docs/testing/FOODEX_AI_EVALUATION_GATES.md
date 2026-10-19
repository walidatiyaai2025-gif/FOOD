# FOODEX AI Evaluation Gates

Issue: **#2230 / AI-21**  
Parent: **#2208**  
Schema: **foodex.ai.evaluation.v1**  
Golden set: \`backend/tests/Fixtures/assistant-evaluations/v1/golden.json\`

## Purpose

AI quality is a release gate, not a subjective review. The evaluation harness extends the existing FOODEX Assistant lineage and treats Laravel/domain services as the authority for permissions, scope, business calculations and evidence.

The model may select tools and narrate grounded results. It may not invent numeric facts, widen scope, bypass retailer sell-out consent, execute SQL, or turn an unapproved draft into a write.

## Two separate score classes

### Blocking deterministic correctness and safety

Every supported profile requires **100%** on deterministic correctness and safety. A provider/model change cannot ship when any case regresses.

Blocking gates are:

- expected Assistant status;
- exact required/allowed tool choice;
- numeric parity against authoritative fixture values and explicit tolerances;
- evidence/provenance presence;
- tenant/store/channel/fact-class scope;
- fail-closed refusal code and no tool call when a denial must stop before execution;
- approval-governed action safety;
- unavailable-data suppression so missing facts cannot become hallucinated values.

These gates intentionally do not judge prose style.

### Non-blocking language quality

Arabic/English language quality is recorded independently through a bounded score and locale match. Wording may vary while facts, permissions, evidence and safety may not.

The initial profile thresholds are 0.80 for agent-capable OpenAI-compatible/Ollama profiles and 0.75 for deterministic fallback. Language quality is advisory in AI-21; factual/permission/safety gates remain blocking.

## Capability profiles

The versioned profile catalog is \`AssistantEvaluationProfileCatalog\`.

- \`openai_compatible.agent.v1\` requires chat, structured JSON, tool calls and agent capability.
- \`ollama_openai_compatible.agent.v1\` has the same blocking contract for Ollama served through the existing OpenAI-compatible path.
- \`deterministic_fallback.v1\` proves the evaluation baseline remains available when an external provider is disabled or incapable.

Profiles do not grant permissions or select business scope. They describe the response capability being evaluated.

## Golden business coverage

The v1 golden set is bilingual and covers:

- wholesale sell-in executive analysis;
- retailer sell-in versus actual sell-out;
- sell-out consent denial;
- inventory risk;
- order lookup;
- approved policy/RAG knowledge with source version;
- role/store permission denial;
- insufficient-data behavior;
- draft-only action safety;
- external-provider disabled deterministic fallback.

Every required golden tool is cross-checked against the runtime \`AssistantToolDefinitionCatalog\`. SELL_OUT golden cases must use a tool whose runtime definition requires sell-out consent.

## Reproducibility and regression behavior

Each golden case contains an immutable baseline observation and exact expected gates. CI also mutates representative observations to prove that the harness fails numeric drift, hallucinated unavailable data, wrong tools, missing evidence, scope drift, invalid refusal behavior and unapproved write execution.

Model/provider experiments may supply observations keyed by case ID to \`AssistantEvaluationHarness::evaluateSuite()\`; the same versioned expectations and profile thresholds apply. A new baseline version must be added instead of silently weakening v1 expectations.

The suite result includes per-profile deterministic, safety and language rates plus an overall blocking result. Deterministic and safety minimums are 1.0.

## Provider-disabled behavior

AI-21 does not make external AI mandatory. The existing deterministic Assistant/provider-failure tests remain the runtime proof that provider failure falls back without making core FOODEX commerce unavailable. The golden fallback profile protects that contract from being lost during later model/provider changes.

## API impact

AI-21 adds internal evaluation code, fixtures, tests and documentation only. It introduces no public \`/api/v1\` endpoint and does not change the OpenAPI contract.
