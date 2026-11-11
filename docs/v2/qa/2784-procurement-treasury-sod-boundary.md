# V2 #2784 / P033 — Purchasing and Treasury SoD authorization boundary

## Source and placement
- Original requirement: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P033 (SECURITY: prevent Purchasing/Treasury approval and delegation limit bypass), master #2503.
- **Placement: REUSE.** Do not add a second UI or Sidebar surface. Purchasing lives in the existing authorized `admin.erp.procurement.index` workbench and `admin.erp.procurement.requests.*` actions; Treasury lives in its existing payment/cashbox workbench and `TreasuryPaymentService` state transitions. Controller and services remain the source of truth for operation permission, legal-entity/store scope, module entitlement and record maker/checker.
- PR target: `Foodex_V2` only. No `main`, production user data, cPanel changes, version promotion, release, or tag.

## Executable code-level evidence
The new synthetic Laravel feature `backend/tests/Feature/V2ProcurementTreasurySodBoundaryTest.php` uses real services, routes and test database records:
1. A Retail purchasing actor who actually has **both manage and approve** permissions creates, submits and attempts to approve their own purchase request through the real POST HTTP endpoint. The decision must be rejected with `decision` validation; submitted state and absence of `decided_by` are preserved.
2. A second actor assigned to a **different Retail store** cannot approve the first store's request even by supplying that store ID. A separately authorized checker in the correct store may approve; the original maker cannot claim a successful approval on subsequent retry.
3. A Treasury actor with both Retail Finance and Retail Store Admin role assignments, and authorized payment read/manage/approve entitlements, creates a cashbox draft but cannot post that same payment because identity-based maker/checker remains mandatory despite aggregated roles.
4. A Treasury actor belonging to a different store (with its own entitlement) cannot post another store's draft. A distinct in-scope checker can post, recording the real approval workflow and audit log exactly once.

This is a regression contract for **existing domain enforcement**, not a new security engine. The tested scope includes store ID and module capability gates; permission state is determined by the live server, not visible buttons. The regressions use synthetic isolated data and do not mutate real users, purchase orders, cash, payment status, or credentials.

## SoD and hard limits
- Strong record-level SoD is by **actor identity and originating transaction**, not just a warning for incompatible role pairs. The procurement `PurchaseRequestManager::decide` checks maker/submission identity before idempotent status handling; Treasury `TreasuryPaymentService::post` checks `requested_by` and store scoping before approving the payment.
- Approval is not authorized merely by owning a generic `platform.manage` or `SUPER_ADMIN` visual presentation. Any additional super-admin finance boundary, AP/GL/poster separate-actor, approval amount/delegation expiry and disabled-module verification depends on existing finance/procurement tests and the broader P127/P129/P130 issues; this PR must not be represented as complete proof of every company/treasury SoD pathway.
- Failures leave approval state unchanged; successful independent decisions retain audit and workflow records. Existing localized error keys and AR/EN UI are reused, with no new markup or translation keys.
- Readiness/entitlement cannot be bypassed by new routes, because this change introduces no route, API, UI or permission. Existing `EnterpriseProcurementWorkbenchTest`, Treasury Payment Foundation, Finance Approval Foundation, scope and module readiness suites remain part of Required CI.

## Cross-app impact matrix
| Surface | Classification | Rationale |
| --- | --- | --- |
| Laravel Backend / Dashboard | **Test + QA evidence only** | Existing procurement/Treasury HTTP and domain services are exercised, not changed. No new permission/API or UI markup. |
| Customer Flutter | Not affected | No Customer API, identity/credit/pricing/order lifecycle or app source changed. |
| Driver Flutter | Not affected | No B2C dispatch, wallet, collection, finance settlement API or Driver source changed. |
| Van Flutter | Not affected | No B2B Van routing/consignment/settlement API or Van source changed. |

## Acceptance tracking
- Expected regression command: `cd backend && php artisan test --filter=V2ProcurementTreasurySodBoundaryTest`.
- Before merging, require exact PR head `Repository Policy`, `FOODEX V2 Isolation Gate`, `FOODEX Required CI Gate`, full Laravel, AR/EN, PHP lint/Pint/PHPStan/Composer security/OpenAPI and real MySQL/Redis fresh-install, upgrade and recovery checks.
- **Keep #2784 open if broader P033 approval limits, financial SoD or real cross-role journeys are not independently evidenced.** Closing after a regression-only PR would overstate acceptance. Manual staging/UAT remains deferred under #2503; deferred UAT is not permission to skip missing server-side requirements.
