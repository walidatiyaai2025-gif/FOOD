# V2 #2699 — Treasury Officer / Cashier (T122R19)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, T122R19; parent #2503. **Target:** `Foodex_V2` only. No changes to `main`, release tags, live cPanel or production.

## Scope and root cause

The canonical built-in `TREASURY_OFFICER` was already correctly declared in `backend/config/job_roles.php`, has localized AR/EN responsibilities, global RBAC role scope with tenant business scope, and requires the `treasury` module. It grants `finance.view`, `finance.manage` and `reports.view`, **not** `finance.approve`, `finance.post`, `reports.export`, `orders.dispatch` or security administration.

However `backend/config/admin.php` omitted the role from the **Wholesale** channel's explicit global-role admission list. `OperationalTenantScope::allowedStoreIds()` and the real Treasury Master controller rely on that list to resolve eligible wholesale stores. As a result, a legitimately assigned role had zero B2B stores even after the Treasury module was enabled; the existing authorized page was a dead destination. This is an access **denial bug**, not a request for broader finance permissions.

**Fix:** extend the existing `admin.channels.b2b.global_roles` admission list with `TREASURY_OFFICER` only. Do not add it to `b2c`, do not broaden its canonical permission bundle, and do not add another controller, policy or Sidebar entry. Role/module availability and per-user DENYs remain authoritative in `User::hasPermission`, and operational store boundaries remain authoritative in `OperationalTenantScope`.

## Placement and UX

- Domain: Treasury / Finance; entity: Cashbox; persona: `TREASURY_OFFICER`; goal: create and maintain authorized wholesale cashboxes and read treasury data.
- **Reuse** existing `Finance → Treasury → Cashboxes` Sidebar destination (`treasury_master`), `admin.treasury.master.index`, `admin.treasury.cashboxes.store` and existing reports. No new page, no UI hierarchy, no independent visibility rule, no raw IDs exposed to business users.
- Existing Treasury Master Dashboard uses the shared responsive/mobile-web component and bilingual AR/EN labels; route tests exercise RTL/LTR and the existing navigation destination. No new translation strings, Blade or frontend assets are introduced.
- B2B remains wholesale/Van-only; global role admission cannot grant B2C Driver operations or retail store access.

## Executable acceptance

New `backend/tests/Feature/V2TreasuryOfficerWholesaleAuthorityTest.php` verifies:

1. Built-in seeded catalog role / exact permission bundle / Treasury module requirement, localized AR/EN governance, Wholesale-only channel admission.
2. Effective positive real HTTP and Sidebar journey in AR/RTL and EN/LTR, Treasury Cashbox create with durable audit event, and one B2B allowed store.
3. Direct denial of Retail/foreign-store GET/POST and missing row creation, denied security management, denied report export and disjoint finance approval/posting privileges.
4. Module-off fail-closed real HTTP, persisted historical role assignment after deactivation, user-specific `finance.manage` DENY preserving view but refusing mutation, and inactive/roleless direct HTTP denial.

No fixture uses production credentials/accounts or modifies live data. Validation uses the repository's full feature/security suite, PHP syntax, Pint/PHPStan, AR/EN, V2 isolation policy, MySQL/Redis fresh installation/upgrade/recovery and exact-head required CI.

## Cross-app impact decision

| Consumer | Classification | Evidence |
|---|---|---|
| Backend / Dashboard | **Affected** | `config/admin.php` only; existing server-authorized B2B Treasury Master route and Sidebar repaired, focused PHP feature tests cover positive/negative access |
| Customer App | **Not affected** | No public `/api/v1` route/schema, customer identity, checkout, pricing, order, customer report or Flutter change |
| Driver App | **Not affected** | No Retail dispatch or driver role/permission/assignment route change; B2C=DRIVER ONLY preserved |
| Van App | **Not affected** | No `van.login`, territory, Van assignment, route, shipment or Flutter change; B2B=VAN ONLY preserved |

No separate V2 mobile issue is needed for this Dashboard-only role admission repair. Any future mutation to driver/van assignment or public API requires its own app-specific V2 issue and parity CI.

## Remaining governance / completion conditions

This PR does **not** resolve the broader cross-domain Finance↔Treasury incompatible-role matrix and exception policy identified in #2851, legal-entity delegation authority, or deferred manual device/staging UAT under #2503. Do not claim these passed solely because the focused role tests passed. Keep #2699 open if full source acceptance is not independently certified; owner-approved cross-domain role policy and final V2 audit are separate gates.

## Exact-head evidence

Record final PR number, tested SHA, CI run IDs, and merge SHA in #2699 after GitHub verification. Merge exclusively into `Foodex_V2` when all required checks including terminal `required-ci-gate` are green. Never merge to `main` or deploy.
