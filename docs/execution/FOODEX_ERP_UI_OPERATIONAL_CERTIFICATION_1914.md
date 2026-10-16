# FOODEX ERP UI Operational Certification — #1914

## Scope

This is the runtime convergence/certification gate for the operator-facing ERP workbenches delivered by #1906-#1913. It adds no new business feature authority and does not replace the canonical W1-W7 domain services or ledgers.

Canonical certification branch: `test/1914-operational-completion-e2e`.

## Mandatory role journeys and evidence

| Journey | Operator surface | Canonical automated evidence |
| --- | --- | --- |
| Procurement | ERP Procurement Workbench | `EnterpriseProcurementWorkbenchTest` plus Procurement domain/foundation tests |
| Warehouse | ERP WMS Workbench | `EnterpriseWmsWorkbenchTest` plus canonical WMS stock/movement services |
| Accountant | Finance Accountant Workbench | `FinanceWorkbenchTest` plus Finance foundation, GL, source-posting, AR, AP, credit and statements suites |
| Treasury | ERP Treasury Workbench | `EnterpriseTreasuryWorkbenchTest` plus Treasury domain suites |
| Customer service | Customer Service Workspace | `CustomerServiceWorkspaceTest` plus canonical customer/order/payment/delivery/conversation authorities |
| Manager | ERP Center → My Work / Action Center → BI | `ErpCenterTest`, `EnterpriseMyWorkTest`, and `EnterpriseBiWorkbenchTest` |

## Runtime visual matrix

The final-convergence branch forces Dashboard visual evidence and captures Arabic RTL and English LTR at desktop 1280, compact 1024, tablet 768, and mobile 390 for:

- ERP Center
- Procurement
- WMS
- Finance
- Treasury
- Customer Service
- My Work / Action Center
- BI / Reports

The evidence pack is fail-closed: missing routes, login redirects, HTTP failures, shell overflow, or missing expected screenshots fail CI.

## Authorization and data-safety assertions

Focused tests cover disabled-module behavior, legal-entity/module entitlement checks, permission checks, store/warehouse scope denial, bounded server-side list behavior, canonical route surfaces, and business-readable localized output. No certification step introduces a parallel ledger, raw-ID workflow, or replacement domain authority.

## Final convergence gates

The branch override intentionally enables:

- operational completion E2E
- backend validation
- Dashboard runtime visual evidence
- Customer validation
- Driver validation
- Van validation
- APP-PREVIEW visual parity
- preview runtime distribution

This full mobile execution is permitted here because #1914 is the explicit final-convergence lane; ordinary backend/dashboard child changes remain path-scoped and do not rebuild unchanged mobile apps.

## Production boundary

Repository-verifiable certification may close #1914 when its exact current head is synchronized with current `main`, all required automated gates are green, and no P0/P1 blocker remains. Genuine human/external/cPanel/reference/real-pilot evidence remains deferred to an explicit Production release under the owner directive; it is neither synthesized nor waived here.
