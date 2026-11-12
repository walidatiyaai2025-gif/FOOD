# FOODEX V2 P279 / Issue #2609 — Finance & Treasury Sidebar group

**Integration boundary:** `Foodex_V2` only; parent #2503. Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P279 (Finance & Treasury).

## Placement and navigation resolution

- Domain/entity: Finance & Treasury; cashboxes/bank accounts, finance legal-entity workbench, treasury store workbench.
- Actor and goal: an authenticated, active ERP-finance actor with an effective Finance-read permission opens an authorized real destination. A global test-only ERP workspace role makes the Finance domain visible; permission still comes from an actual scoped Retail role.
- Workflow/data: routine accounting and Treasury read workflows with sensitive, store/legal-entity-scoped business records. Existing pages already implement business tabs, filters and action controls.
- **Reuse** the existing `AdminNavigation::groupsFor` `finance` group. It is not a permission, and it must not authorize a route by itself. No new Sidebar group, route, security bypass, duplicated ledger or layout.
- Navigation type: `SIDEBAR`. Navigation owner: `AdminNavigation` with existing `AdminSidebarManager::apply`; entry point: Finance business group, fallback/back path: existing Dashboard navigation. Children remain canonically ordered: `treasury_master` → `admin.treasury.master.index` (store scoped `finance.view`); `erp_finance` → `admin.erp.finance.index` (Finance enabled + version/health readiness + entitlement + `finance.view`); `erp_treasury` → `admin.erp.treasury.index` (Treasury enabled + version/health readiness + entitlement + `finance.view`).
- UI: existing responsive Dashboard pages in AR/RTL and EN/LTR, no markup changes. 320/360/390/412/430/768/1024/1280 viewport UAT is deferred to final V2 gate; no claim of manual device rendering. The group label comes from `admin.nav_groups.finance` in both locales.
- Field classification: no fields added. Existing Finance and Treasury workbenches own Lookup/Enum/business inputs; navigation does not expose IDs to operators.

## Automated acceptance and security

`backend/tests/Feature/V2FinanceSidebarGroupAuthorityTest.php` executes real seeded roles, scoped store assignment, module state/entitlements and Dashboard HTTP routes. It checks:
- unique AR/EN Finance group and exact three-child projection when both independently eligible modules are operational, unique routes/links and rendered RTL/LTR pages;
- module-disabled Finance and Treasury workspaces absent without dead links, while independent authorized Treasury Master remains accessible;
- uncertified-but-ENABLED Finance module does not appear as a ready Sidebar destination;
- global explicit `finance.view` DENY removes the group and blocks direct HTTP access to the same three read routes;
- other Retail store / B2B store scope denial, roleless and inactive identity denial;
- no finance mutation or API/ledger schema changes.

Caveat requiring a distinct source acceptance check: `FinanceWorkbenchController::index` and `EnterpriseTreasuryController::index` inspect module enabled state and per-context permission, but the Sidebar additionally requires operational readiness; a direct request with enabled-but-uncertified module may require an independent route-readiness hardening contract. The same applies to legal-entity-only Finance entitlements not automatically navigable under the current Sidebar STORE/platform entitlement rule. Do not claim full policy parity until independently demonstrated or remediated without overlapping shared-file PR ownership. `backend/app/Support/AdminNavigation.php` is currently claimed in #3246; Finance Controller in #3218; keep files untouched.

## Cross-surface impact

- Backend: only new isolated Feature test; no API payload, route, permission, migration or finance ledger change.
- Dashboard: reuses existing Finance/Treasury pages and navigation, with no rendered UI changes; automated AR/EN and direct HTTP evidence.
- Customer app: no customer commerce API, credit, pricing or Flutter behavior change.
- Driver app: no B2C fulfillment, wallet, assignment, status or Flutter change.
- Van app: no B2B assignment, field inventory, visit, routing or Flutter change.

B2B → VAN ONLY and B2C → DRIVER ONLY remain unchanged. No new app Issue is appropriate for unchanged mobile contracts.

## CI and source acceptance

Exact-head Repository Policy, V2 Isolation, PHP syntax/Pint/PHPStan/security, full Laravel, AR/EN localization, MySQL/Redis fresh-install/upgrade/recovery and terminal `required-ci-gate` are mandatory before V2-only squash merge. No `main`, release, tags or production deployment. Parent #2503 defers **manual** UAT to final V2 gates, not unresolved code/security/authorization defects. Record live checks/head SHA in Issue/PR and keep the Issue open if broader source parity remains incomplete.
