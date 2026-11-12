# V2 P287 — Accounts Payable (AP) canonical Dashboard authority

Issue: #2617 (P287); parent #2503. Integrate only into `Foodex_V2`; no main, tags, release, installer rollout or production deployment.

## Placement, access and discoverability

| Dimension | Resolved authority |
| --- | --- |
| Domain | Finance & Treasury / Accounts Payable |
| Entity | Supplier AP document and amount outstanding |
| Role | Authorized Finance users with `finance.view` scoped to the actual store; independent `finance.manage` and `finance.post` authority for write/approval flows |
| User goal | Review supplier payables, maturity, outstanding amounts, and related ledger evidence |
| Workflow / frequency | High-frequency read/list/filter, action-driven linked ledger drill-through |
| Data density | Bounded, paginated operational grid and aging table |
| Parent / existing route | `admin.erp.finance.index?tab=payables&store_id={authorized_context}`, current Finance Workbench |
| Controller / service | `FinanceWorkbenchController::index` → `payableData` → `AccountsPayableService::paginate/aging` |
| Placement | **Reuse → PAGE_TAB**; not a new Sidebar route, domain duplicate, or direct-URL-only destination |
| Navigation owner | Authorized Finance/ERP domain group and Finance Workbench page tabs |
| Permission | Backend `finance.view`, legal-entity entitlement `finance.ap.read`, enabled `finance` module and certified readiness |
| Active context | Selected store + channel resolved server side to legal entity; invalid/foreign selector rejects with 404 |
| Desktop / tablet / mobile web | Shared `admin.finance-workbench` page, tab rail, responsive filters and horizontally scrollable bounded finance grid |
| RTL / LTR | Existing `finance_workbench` AR/EN catalogs with language/direction HTML attributes |
| Empty/error/disabled states | Localized empty grid; 403 for missing module/capability/permission; 404 for foreign store |
| Back path | Finance domain / Workbench tabs, existing Dashboard global sidebar |

All data-entry IDs (store, supplier, journal) come from scoped server-owned context or canonical records, not unbounded business-user raw IDs. Supplier search/reference is an existing legitimate lookup/filter; writing AP source documents remains governed by `AccountsPayableService` authorization and maker/checker controls. No new AP creation or posting route is introduced by a read-only sidebar node.

## Exact security and finance source

- Module gate: `ModuleRegistry::isEnabled(FinanceFoundationService::MODULE)` in the HTTP controller.
- Scoped context: `FinanceWorkbenchController::contextOptions`, `selectedContextOption`, `resolveContext`.
- UI availability: `accessMatrix()['ap_read']` and `tabDefinitions`, both use the same exact `finance.view` and `finance.ap.read` authority as `AccountsPayableService::authorizedScope`.
- Financial rows: `AccountsPayableService::documentQuery` filters `organization_id`, `company_id`, `legal_entity_id`, and `store_id`. Aging uses the same exact enterprise scope. No cross-store document fallback.
- Positive and negative evidence: `backend/tests/Feature/V2AccountsPayablePageAuthorityTest.php` includes rendered Arabic/English tab with real AP documents from two distinct stores, direct foreign-store 404, disabled Finance module 403, missing AP entitlement 403 even with foundation view, and global explicit deny overriding assigned role.
- Existing service contract `backend/tests/Feature/Enterprise/EnterpriseAccountsPayableFoundationTest.php` proves write/post/allocations idempotency, maker/checker and audit. Existing `FinanceWorkbenchTest.php` proves all Finance tabs and route definitions.

## Scope review

| Surface | Impact |
| --- | --- |
| Backend / Dashboard | New exact-route Laravel regression tests and QA proof. Runtime code and existing production UI stay unchanged. |
| Customer Flutter | No shared API payload, customer wallet, supplier payable records or routes changed. Not applicable. |
| Driver Flutter | No delivery, collection, salary, reconciliation API, or client code changed. Not applicable. |
| Van Flutter | No B2B Van assignment, collection, stock/visit API, or client code changed. Not applicable. |

No mobile impact issues were created because only Dashboard tests/docs were changed; existing **B2B→Van exclusively / B2C→Driver exclusively** contract remains unchanged.

## Required CI and closure

`php artisan test --filter=V2AccountsPayablePageAuthorityTest` and existing Finance/AP source tests should pass, as well as PHP/Pint/PHPStan, localization, full required Laravel suite and MySQL/Redis install/upgrade/recovery acceptance on the **same PR final HEAD**. At least five negative/positive route tests are included to detect changed auth behavior. AR/EN and RTL/LTR verified at HTTP-render level; full browser/a11y device UAT is tracked on #2503/#2750, not claimed as run here.

A technical leaf is complete only when source authority is fully functional, **all exact-head Required CI checks are green**, the single PR is merged into `Foodex_V2`, and the final commit is verified. Any manual UAT deferral must be explicitly marked `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503. No main or production actions.
