# V2 #2610 / P280 — Finance Center authoritative navigation and HTTP contract

**Integration:** `Foodex_V2` only. Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09 (matrix P280), parent #2503.

## Reuse and business placement

- **Domain/entity:** Finance & Treasury / legal-entity Finance Workbench.
- **Actor/goal:** an active, permitted Finance reader chooses an authorized store and sees scoped accounting summaries and read tabs, never another store's records.
- **Pattern:** REUSE the existing authorized Finance group `finance` with single `erp_finance` child, labeled `admin.erp_center.modules.finance.title`. Entry: existing `admin.erp.finance.index`, `GET /admin/erp/finance`, existing `admin.finance-workbench` responsive tabbed view. No duplicate Sidebar destination, new form, or technical ID entry.
- **Navigation authority:** `AdminNavigation::canAccessPlatformErp`, `routeItemEnterpriseModule`, `finance.view` effective scoped RBAC, module lifecycle/readiness, and module entitlement. Nav entry does not grant permission.
- **Server authority:** `FinanceWorkbenchController::index` checks the enabled Finance module, resolves only permitted active B2B/B2C store contexts, enforces `finance.view` at selected store and legal-entity Finance capabilities, denies foreign store IDs with 404, and reuses existing real tab/data services. Mutation routes independently require Finance write capabilities; P280 does not extend them.

## Executable verification

`backend/tests/Feature/V2FinanceCenterNavigationAuthorityTest.php` checks a real Finance store/account hierarchy with CoreReferenceSeeder, enabled and certified Finance module, exact store and legal-entity entitlements, single Finance Center route in AR/EN, RTL/LTR real HTML/200, module disabled hidden/403, explicit permission DENY hidden/403, cross-store 404, and inactive/roleless denied.

The test creates local ephemeral fixtures only, with no production users, grants, balances, posting or data edits. Existing `FinanceWorkbenchTest.php` retains complete per-tab, scope, read/write, and localization tests. No duplicate Finance service/controller/Blade code.

**Scope note:** the existing navigation compositor accepts platform/store Finance entitlements; the Finance workbench evaluates legal-entity capabilities. The test supplies both exact layers and does not claim that every possible legal-entity-only entitlement yields a navigable Sidebar item. Any discrepancy between legal-entity-only access and navigation must be audited before claiming full multi-role completion; do not bypass exact entitlement checks.

## Consumer and security matrix

- **Backend/Dashboard:** existing Finance read UI and route exercised; no API shape changes, no new controls, permissions, modules or migrations. Selected store is always server rechecked. Existing component renders AR/RTL and EN/LTR.
- **Customer:** no public finance/customer endpoint, stored balance, credit or Flutter contract changes.
- **Driver:** no B2C Driver dispatch, collection, wallet, notification or Flutter contract changes.
- **Van:** no B2B Van dispatch, inventory, collection, route or Flutter contract changes. B2B VAN ONLY / B2C DRIVER ONLY remain unchanged.

## Acceptance/limits

Automated test is executable source authority evidence, **not** a production deployment or visual device UAT. Required exact-HEAD Repository Policy, V2 Isolation, Laravel/lint/static security, AR/EN, MySQL/Redis install/upgrade/recovery and final required-ci-gate must all pass before merging. Record run links and final Git SHA in Issue/PR. Manual visual acceptance is `DEFERRED_TO_V2_FINAL` under parent #2503, but missing functional/source coverage is not thereby waived.
