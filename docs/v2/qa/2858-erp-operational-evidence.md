# FOODEX V2 #2858 / P135 — ERP operational readiness requires a real workflow proof

**Integration**: `Foodex_V2` only. Parent: #2503. Source: Unified RBAC / ERP / Sidebar discussion P135.

## Requirement and authoritative placement

An ERP module lifecycle state of `ENABLED` does not certify operational usability. A healthy probe, broad platform role or `certified_versions` entry alone cannot turn on an operational ERP shortcut. Extend the **existing** `ModuleOperationalReadiness::status` used by the ERP Center and module administration, not a second Sidebar policy or new screen.

The authorization chain is distinct: authenticated role/permission -> tenant + channel + store scope -> module entitlement -> enabled module lifecycle -> exact manifest-version certification -> complete reviewed workflow evidence -> healthy runtime probes -> server-authorized operational route. A Sidebar link never bypasses the HTTP controller, RBAC, finance maker/checker or resource-level guards.

## Source-controlled workflow matrix (no automatic certificates)

`backend/config/enterprise_module_readiness.php` records mandatory evidence stages for five currently registered ERP modules:

| Module | Required operational stages |
| --- | --- |
| `procurement` v2 | request, review, approve, purchase order, goods receipt, invoice, return, report, audit |
| `inventory_wms` v1 | receive, reserve, pick, count, adjust, reverse, report, audit |
| `finance` v1 | create, review, approve, post, reverse, reconcile, report, audit |
| `treasury` v1 | receive, review, approve, settle, reconcile, reverse, report, audit |
| `enterprise_bi` v1 | read, filter, scope, export, report, audit |

Every newly registered module in category `operations`, `finance` or `analytics` fails closed until it has a nonempty workflow matrix. `smart_ordering` and `sample_optional` are not silently reclassified as ERP.

Two separately reviewed settings are necessary:

1. `certified_versions[module]` must equal the active module manifest version.
2. `workflow_attestations[module]` must contain that exact version, a 40-character tested Git SHA, review Issue number, canonical FOODEX GitHub Actions run URL and a named passing test for **every** required workflow step.

Both approval maps ship **empty**. An operator cannot declare readiness through a request parameter, entitlement, module lifecycle change, UI toggle or environment variable. Evidence data is checked for structure and completeness; runtime does **not** contact GitHub to independently authenticate a claim in configuration. Maintainers must validate CI run, tested SHA, test code and original acceptance before committing any real attestation. Synthetic fixtures in Laravel tests are not legitimate certificates.

## Automated evidence and security

- `V2ErpOperationalWorkflowEvidenceTest`: default absent approval, mandatory ERP module matrices, no implicit readiness for new modules, Arabic/English fail-closed, missing required step, wrong manifest version, malformed SHA/reviewer/CI URL, and an unhealthy enabled module.
- Existing `EnterpriseModuleOperationalReadinessTest` preserves separate lifecycle/version/health behavior for non-ERP optional modules.
- `ErpCenterTest` uses explicitly **synthetic test-only** provenance to retain its existing authorized CTA fixture cases; actual deployment config contains no such attestations. ERP Center continues to fail closed when certified-version or entitlement is missing.
- No schema/data migration, financial transaction, seed change, permission grant, new endpoint, production mutation, or release artifact.

### Product impact

| Surface | Impact |
| --- | --- |
| Backend | **Affected:** operational status evaluation for ERP categories; fail closed without complete per-stage attestation |
| Dashboard | **Affected through existing ERP Center and module-control diagnostics:** uncertified modules remain informational; no new Blade/JS/sidebar screen |
| Customer Flutter | **Not affected:** no Customer API, auth, ordering, pricing, response DTO or Flutter changes |
| Driver Flutter | **Not affected:** no Driver routes, status, GPS, wallet, dispatch or B2C DRIVER ONLY contract change |
| Van Flutter | **Not affected:** no Van routes, visits, allocations, collections, or B2B VAN ONLY contract change |

Authorization remains in existing server-side controllers and services; no mobile API payloads changed. AR/EN strings are unchanged, and existing tests cover ERP diagnostics localization. No production permission/scope/entitlement is changed.

## Incomplete scope / acceptance handoff

This change enforces a **proof prerequisite**, not G0-G13 certification for all five modules. Before #2858 can be closed, module owners must implement and verify the real create/review/approve/receive/post/reverse/report/audit business journeys, true role and data-scope allow/deny, cross-store/channel negatives, finance SoD, transaction rollbacks, and reviewable exact-SHA CI artifacts for every required step. An empty certification list is intentional and safe, not evidence that business workflows passed.

Obtain actual AR/EN RTL/LTR desktop/tablet/mobile, keyboard and screen-reader acceptance at the final V2 gate on #2503 / #2750, per the owner's 2026-11-10 acceptance deferral. Do **not** declare production readiness, update `main`, publish releases/tags, or deploy from this branch.

**CI gate before any merge:** Repository Policy + V2 Isolation + Laravel/Pint/PHPStan/security/OpenAPI + AR/EN + real MySQL/Redis clean install/upgrade/recovery + terminal required-ci-gate, all green on the exact PR head.

## CI rescue — synthetic readiness fixtures (2026-11-10)

The first exact-head Required CI of this prerequisite failed 28 existing tests across 15 ERP workbench/navigation suites because their fixtures explicitly certify `certified_versions` but had no new workflow proof. Each affected suite now opts into `Tests\\Support\\SyntheticErpWorkflowEvidence::install()` only in Laravel test setup. The helper provides structurally valid, clearly synthetic per-step evidence for **test simulations**, at each module's manifest version. Tests that deliberately omit version certification remain uncertified; P135 negative default and malformed/missing workflow-evidence tests do not call this helper.

This is **not** new production evidence, live readiness, proof of workflow execution, CI verification, or approval. Real `certified_versions` and `workflow_attestations` remain empty. Genuine workflow rollout and independent acceptance require reviewed module-specific exact-SHA proof and owner authorization. This fixture is never referenced by application, seed, deployed config, migration, or public API code.

## Post-reconciliation Laravel fixture regression (2026-11-11)

After the P135 workflow-evidence gate, V2 Bank Reconciliation P297 and Receivables Aging P288 tests inherited pre-prerequisite positive fixtures which set only `certified_versions`. Their intended positive finance/treasury HTTP paths must opt into `SyntheticErpWorkflowEvidence::install()` in test setup, matching existing ERP suites. Disabled-module, missing entitlement, explicit user DENY, cross-store and maker/checker negative HTTP assertions stay unchanged; production module certifications and workflow attestations remain empty. This preserves fail-closed runtime enforcement without asserting genuine G0–G13 completion. These test changes are CI compatibility for merged P297/P288 coverage, not new Bank/Treasury product behavior.
