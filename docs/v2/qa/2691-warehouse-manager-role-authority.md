# FOODEX V2 #2691 — WAREHOUSE_MANAGER operational authorization (T122R11)

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / T122R11. Coordinator #2503. Integration exclusively `Foodex_V2`. Original branch `test/2691-v2-warehouse-manager-authority` and original PR #3214 are preserved; no replacement, main merge or production release.

## Role, page placement and least privilege

**Reuse** the seeded bilingual `WAREHOUSE_MANAGER` / مدير المستودع job role, existing Inventory/WMS navigation and `admin.erp.wms.index` Workbench. No new page, tabs, routes, API or authorization engine. `backend/config/job_roles.php` defines a global role scoped to the Wholesale business tenant requiring `inventory_wms` (optional `procurement`), with only `inventory.view`, `inventory.adjust`, `inventory.manage`, `catalog.view`, `reports.view`. It does not grant Finance, approvals, order dispatch, security administration or Retail store permissions.

`backend/config/admin.php` currently omits WAREHOUSE_MANAGER from `admin.channels.b2b.global_roles`; admitting the existing role to B2B is the narrowly scoped source fix, not an added permission. **Do not grant B2C global access.** The `OperationalTenantScope`, `WarehouseScope`, module certification and STORE entitlement checks remain authoritative for actual HTTP operations, read versus manage controls and warehouse/store IDOR denial.

## Executable tests

Original `backend/tests/Feature/V2WarehouseManagerRoleAuthorityTest.php` checks seeded role bundle, Arabic/English presentation, disabled WMS module revocation with persisted assignment, role's lack of Finance/Orders/Security grants, denied direct Security HTTP/API, global explicit DENY precedence and historical audit assignment.

The resumed same-branch test adds an authenticated operational journey:
- Without exact STORE WMS entitlement, an enabled WMS module does **not** permit direct WMS HTTP; route remains 404.
- With certified module, valid Wholesale store entitlement and the seeded manager role, B2B entry/permission and real WMS Warehouses tab return 200 in both `ar`/RTL and `en`/LTR. Explicit warehouse context remains store-bound.
- A Retail store remains unavailable (404) to the globally scoped Wholesale manager even when its WMS entitlement is enabled. No forced `SUPER_ADMIN` or substitute inventory role.
- Effective `inventory.manage` is permitted only in the authorized Wholesale store; `finance.approve` and the Retail store's `inventory.view` remain false.

## Current source dependency and file ownership

#2691 was previously left DRAFT because its original issue-test PR did not contain a source fix for the missing B2B global-role admission. The old shared-file owner #2699 / PR #3179 was released and closed without merge. At this checkpoint the same file, `backend/config/admin.php`, is **actively owned by #2684 / PR #3222** (Sales Officer), whose exact-head CI is not yet green. This worker **must not modify that shared file in the #2691 branch** until #2684 is merged/released. The branch can safely prepare its original test/docs files independently, then synchronize to the latest `Foodex_V2` SHA, add only `WAREHOUSE_MANAGER` to the B2B list and reopen original PR #3214. CI from the old test-only commit is not approval for that uncommitted change.

## Surface impact matrix

| Surface | Assessment | Evidence |
| --- | --- | --- |
| Laravel Backend | Affected: minimal B2B role channel admission, when file ownership clears | Existing `OperationalTenantScope`, `WarehouseScope`, WMS module/STORE entitlement guards reused |
| Dashboard | Existing WMS business domain/workbench reused | New AR/EN/RTL-LTR real HTTP and server authority tests; no new screen |
| Customer Flutter | Not affected | No customer auth/API/catalog/pricing/order contract/source changes |
| Driver Flutter | Not affected | No B2C Driver-only dispatch, proof, wallet or route contract change |
| Van Flutter | Not affected | No B2B Van-only visits, stops, warehouse pickup or delivery API/Flutter change |

B2B **VAN ONLY** and B2C **DRIVER ONLY** remain unchanged. Manual browser/device/UX acceptance is deferred to #2503/#2750; automated guards and tests cannot be deferred.

## Merge and closure gate

Original branch `test/2691-v2-warehouse-manager-authority` → existing original PR #3214 → **Foodex_V2 ONLY**, one issue/branch/PR. All real source work must be included on the exact final PR SHA and all required GitHub checks must complete SUCCESS: Repository Policy, FOODEX V2 Isolation Gate, full Backend Laravel, PHP syntax/Pint/PHPStan/security, AR/EN localization, MySQL/Redis Fresh Install/Upgrade/Recovery and terminal `required-ci-gate`. Fix red CI in place; do not reuse older green results. Record exact commit/merge evidence, close only code-complete issue under owner 2026-11-10 policy with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. No `main`, tags, release, staging or production.
