# FOODEX V2 — #2694 / T122R14 Accounting Manager

**Contract source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` → `T122R14`; parent #2503. **Target:** `Foodex_V2` only. No main, tags, releases or production.

## Architecture and authority — Reuse → Extend → Compose → New Screen
**Reuse** canonical built-in `ACCOUNTING_MANAGER` from `backend/config/job_roles.php`, canonical `FinanceWorkbenchController` / `admin.erp.finance.index`, Finance module lifecycle/readiness, `FinanceApprovalService`, `GeneralLedgerService`, `WorkflowEngine`, `TenantContextResolver` and effective user permission/entitlement checks. **Extend** only new issue-specific role HTTP and maker/checker regression; do not create a new Finance screen, approval service, route, sidebar registry or role policy.

Role is a global tenant-facing Finance manager with **required** `finance` module and optional `treasury` module; persisted `finance.view/manage/post/approve`, `reports.view/export`. No security administration, user deletion, procurement issue or Platform Owner privilege.

## Resolved integration dependency (fail closed)
- The canonical B2B `admin.channels.b2b.global_roles` in `backend/config/admin.php` now admits `ACCOUNTING_MANAGER` by itself. The permission is added to **B2B only**; the B2C allowlist remains unchanged. This resolves the independently proven denial from `TenantContextResolver::wholesale()` without requiring an extra `FINANCE` or `SUPER_ADMIN` role.
- #2698 / PR #3297 (Treasury Manager) and #2583 / PR #3298 (Warehouses navigation) were integrated before #2694's shared-file change. The merge reconciliation on original PR #3300 preserves both `TREASURY_MANAGER` and the subsequently integrated `SALES_MANAGER` (#2683 / PR #3302), instead of overwriting either peer's config.
- The original role-only regression intentionally failed before the B2B admission fix; after the fix, the issue's tests remain authoritative. No assertion was weakened, no second role was injected, and no `AdminNavigation` or B2C store scope was altered.
- Review the final exact-head Laravel, Repository Policy, V2 Isolation, localization, MySQL/Redis deployment and Required CI results before merge. Passing CI for an earlier head cannot authorize a newer commit.

## Role, route and workflow acceptance
- Existing Finance Workbench `admin.erp.finance.index?store_id=...` / Accounts tab is authorized only when actor has exact scoped role, Finance module is enabled/certified/healthy, and legal-entity `finance.foundation.read` capability is entitled; AR/RTL and EN/LTR existing view verified.
- A forced B2C `store_id` is rejected even when the actor is an authorized Wholesale Accounting Manager; module capability and `finance.manage` role permission alone cannot allow a `finance.foundation.manage`-free account creation.
- Seeded role permission bundle must include `finance.approve` and `finance.post`, but the same requester cannot approve a workflow step. A separate Accounting Manager checker with a registered, bounded `FinanceApprovalService` limit can approve a real general-ledger journal, while its maker and checker are barred from posting. Independent third poster completes the transaction, ledger entries and audit evidence.
- Reuse existing authority and regression from `V2FinanceTreasuryDutySeparationTest` / `V2ProcurementFinanceTreasuryRoleSeparationTest` instead of adding a second maker/checker policy. New role-specific test: `backend/tests/Feature/V2AccountingManagerRoleAuthorityTest.php`.
- Role, module, entitlement and finance maker/checker checks must apply at the **HTTP/service** boundary, not simply hide Sidebar links.

## Explicit application impact
| Surface | Classification | Details |
| --- | --- | --- |
| Backend Laravel | **Affected: B2B role admission + regression** | Add `ACCOUNTING_MANAGER` to the B2B operational allowlist only; verify real HTTP Finance access, scoped denial and ledger SoD |
| Management Dashboard | **Existing route authorized for Accounting Manager** | Reuse Finance Workbench with existing RBAC, module/entitlement, AR/EN, responsive/a11y; no new screen or Sidebar route |
| Customer Flutter | **Not affected** | No customer account, credit, order, catalogue, API, routing, token or notification change |
| Driver Flutter | **Not affected** | No B2C Driver-only dispatch, collection, settlement, proof or client API change |
| Van Flutter | **Not affected** | No B2B Van-only visits, routes, collections, consignment or API change |

## CI and non-release evidence
Focused test: `cd backend && php artisan test --filter=V2AccountingManagerRoleAuthorityTest`; full Required CI (including MySQL/Redis fresh install/upgrade, full Laravel suite, AR/EN, PHP/Pint/PHPStan, security), Repository Policy and V2 Isolation must be successful on the same final PR head before merge. Do not assert a test passed unless verified from GitHub Actions. No deployment is authorized. Final cross-persona manual browser/mobile-web accessibility acceptance remains with #2503/#2750.
