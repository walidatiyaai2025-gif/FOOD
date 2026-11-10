# V2 P132 / Issue #2855 — Account deletion privacy retention

**Source:** FOODEX Unified RBAC/ERP/Sidebar 2026-11-09, paragraph P132. Parent #2503. Integration: `Foodex_V2` only; no production promotion.

## Existing canonical feature and placement
Reuse existing Customer Account > Request account deletion action and `POST /api/v1/account-deletion`; `GET /api/v1/account-deletion` remains the status endpoint. Reuse `AccountDeletionController`, `AccountDeletionService`, the persisted account-deletion request, platform/managed-role offboarding and the audit logger. No new Sidebar, route, entitlement, migration, or destructive cleanup. Existing authenticated endpoint and password confirmation remain mandatory.

## Security change
- Split `customers.id`, `b2b_customers.id` and `b2c_customers.id` into typed identity sets before querying orders, invoices, or B2B accounts; numerical collisions between these tables never constitute ownership. Account matching uses BOTH `b2b_accounts.customer_id` for legacy IDs and `b2b_accounts.b2b_customer_id` for B2B domain IDs under a grouped OR predicate.
- `outstanding_finance` from optional denormalized columns is only a legacy signal, **not** evidence that invoices/receivables/ledger balances are cleared. Financial review is mandatory for any B2B contractual profile, linked account, financial invoice, B2B ledger entry or enterprise AR document. This is deliberately fail-closed: settled or unverified contractual history is held for authorized human retention decisions, not self-service instant anonymization.
- Existing active-order retention and managed Driver/Van offboarding take precedence. If neither applies, Customer-only accounts without financial evidence can continue normal self-service anonymization; unrelated tenant/customer records remain untouched.
- `financial_review_required` is a boolean in existing API `retention` object (no financial amounts or secrets). Retain invoices, payments, ledger, audit, supplier obligations, orders, and uploads; this PR performs no financial or historical row deletion and no retrospective data rewrite.
- AR/EN translation for verification failure and blocking messages uses Laravel's selected locale. Status values remain the existing machine-readable `PENDING_RETENTION` / `OPERATIONAL_REVIEW_REQUIRED` / `COMPLETED` contract.

## Actor, scope, and controls
| Actor / surface | Action and enforcement | Contract |
| --- | --- | --- |
| Authenticated Customer (B2C/B2B) | Existing password-verified deletion POST and stored status | Own `user_id` only; typed legacy/B2B/B2C relationships, no cross-account matches |
| Store staff or Super Admin | Customer self-service must reject managed/global roles or store role assignments | Existing managed RBAC offboarding, no store/module bypass |
| Driver / Van | Self-deletion never immediately deactivates operational identity | Existing `OPERATIONAL_REVIEW_REQUIRED` and managed offboarding |
| Finance / treasury | No self-service write to financial records | Existing finance maker/checker, settlement, audit and legal retention process remains authoritative |

## Cross-application impact
- **Customer: affected** — a B2B or financially linked Customer request now safely returns the already-supported `PENDING_RETENTION` status instead of potentially immediately anonymizing. The Customer app currently displays the raw status identifier; a linked V2 Customer-specific issue must cover AR/EN user-facing status localization and actual mobile acceptance before closing #2855.
- **Driver: not affected** — the same operational-role decision, API route, status and managed process are preserved; no Driver app payload/dispatch logic changes. Re-run existing operational status regression.
- **Van: not affected** — `VAN_OPERATOR` continues `OPERATIONAL_REVIEW_REQUIRED`, no fulfillment, dispatch, role, Van API or Flutter changes. Regression is in the new test.

## Acceptance matrix
1. B2B domain customer with an account linked through `b2b_customer_id` (even with a different `customer_id`) -> `PENDING_RETENTION`, identity and financial rows untouched.
2. Separate customers with numerically identical IDs across domain tables -> no cross-user retention decision, no foreign-row anonymization.
3. No physical AR balance column + B2B contract -> cannot auto-anonymize; HTTP status and retention flag proven.
4. Wrong password -> validation failure without persisted deletion request. Driver/Van operational roles -> managed review only.
5. Future QA: actual open invoices, captured/paid allocations, manual ledger adjustments, mixed currency, enterprise AR, and concurrent/race review; do not claim these audited solely from the no-balance check.
6. Required exact-head CI: Repository Policy, V2 Isolation Gate, Required CI Gate, PHP/Pint/PHPStan/Security/OpenAPI, Laravel Feature suite, MySQL/Redis fresh-install/upgrade/recovery. Record run URLs and conclusion before merge.
7. Independent isolated V2 staging Customer app/browser/AR RTL/EN LTR/keyboard/small-viewports and finance-role retention approval must be evidenced before issue closure.

**Status:** Code and tests require exact-head CI and independent acceptance. No production deploy, tag, ZIP promotion or `main` merge permitted.
