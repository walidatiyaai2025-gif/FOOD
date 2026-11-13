# FOODEX V2 — #2694 / T122R14 Accounting Manager

**Contract source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` → `T122R14`; parent #2503. **Target:** `Foodex_V2` only. No main, tags, releases or production.

## Architecture and authority — Reuse → Extend → Compose → New Screen
**Reuse** canonical built-in `ACCOUNTING_MANAGER` from `backend/config/job_roles.php`, canonical `FinanceWorkbenchController` / `admin.erp.finance.index`, Finance module lifecycle/readiness, `FinanceApprovalService`, `GeneralLedgerService`, `WorkflowEngine`, `TenantContextResolver` and effective user permission/entitlement checks. **Extend** only new issue-specific role HTTP and maker/checker regression; do not create a new Finance screen, approval service, route, sidebar registry or role policy.

Role is a global tenant-facing Finance manager with **required** `finance` module and optional `treasury` module; persisted `finance.view/manage/post/approve`, `reports.view/export`. No security administration, user deletion, procurement issue or Platform Owner privilege.

## Discovered integration dependency (fail closed)
- The current `admin.channels.b2b.global_roles` in `backend/config/admin.php` **does not contain `ACCOUNTING_MANAGER`**. Therefore, a lone Accounting Manager cannot select the canonical Wholesale B2B context via `TenantContextResolver::wholesale()` even after Finance enablement and entitlement. Assigning an extra `FINANCE` or `SUPER_ADMIN` role would be an unacceptable workaround.
- `backend/config/admin.php` is owned by active #2698 Treasury Manager. #2694 does **not** edit that file or circumvent its ownership; explicit handoff or completed #2698 merge is required before the narrow additive `ACCOUNTING_MANAGER` B2B membership fix. A cross-worker dependency comment is recorded on #2698.
- `backend/app/Support/AdminNavigation.php` remains owned by active #2583; it requires proper read-projection parity but must not be modified by #2694 until that lease is released/merged.
- The new test intentionally asserts the intended **single-role operational B2B** contract. It may be red while the above shared-file dependencies are unresolved; **no merge may happen on red tests**. Do not weaken assertions, fake another role, alter store tenancy, or infer application acceptance.

## Role, route and workflow acceptance
- Existing Finance Workbench `admin.erp.finance.index?store_id=...` / Accounts tab is authorized only when actor has exact scoped role, Finance module is enabled/certified/healthy, and legal-entity `finance.foundation.read` capability is entitled; AR/RTL and EN/LTR existing view verified.
- A forced B2C `store_id` is rejected even when the actor is an authorized Wholesale Accounting Manager; module capability and `finance.manage` role permission alone cannot allow a `finance.foundation.manage`-free account creation.
- Seeded role permission bundle must include `finance.approve` and `finance.post`, but the same requester cannot approve a workflow step. A separate Accounting Manager checker with a registered, bounded `FinanceApprovalService` limit can approve a real general-ledger journal, while its maker and checker are barred from posting. Independent third poster completes the transaction, ledger entries and audit evidence.
- Reuse existing authority and regression from `V2FinanceTreasuryDutySeparationTest` / `V2ProcurementFinanceTreasuryRoleSeparationTest` instead of adding a second maker/checker policy. New role-specific test: `backend/tests/Feature/V2AccountingManagerRoleAuthorityTest.php`.
- Role, module, entitlement and finance maker/checker checks must apply at the **HTTP/service** boundary, not simply hide Sidebar links.

## Explicit application impact
| Surface | Classification | Details |
| --- | --- | --- |
| Backend Laravel | **Affected: test-only contract currently** | Added role-specific HTTP + real ledger SoD regression; no production API/service code edited pending shared-file handoff |
| Management Dashboard | **Existing route affected by discovered gap** | Finance Workbench reuse; B2B actor admission and AR/EN responsive/a11y must be certified by existing and new tests |
| Customer Flutter | **Not affected** | No customer account, credit, order, catalogue, API, routing, token or notification change |
| Driver Flutter | **Not affected** | No B2C Driver-only dispatch, collection, settlement, proof or client API change |
| Van Flutter | **Not affected** | No B2B Van-only visits, routes, collections, consignment or API change |

## CI and non-release evidence
Focused test: `cd backend && php artisan test --filter=V2AccountingManagerRoleAuthorityTest`; full Required CI (including MySQL/Redis fresh install/upgrade, full Laravel suite, AR/EN, PHP/Pint/PHPStan, security), Repository Policy and V2 Isolation must be successful on the same final PR head before merge. Do not assert a test passed unless verified from GitHub Actions. No deployment is authorized. Final cross-persona manual browser/mobile-web accessibility acceptance remains with #2503/#2750.
