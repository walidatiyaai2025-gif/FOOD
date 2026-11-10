# FOODEX V2 #2714 — Finance & Accounting Persona (T353R5)

**Integration target:** `Foodex_V2` only. **Source:** FOODEX Unified RBAC/ERP/Sidebar decision T353R5, parent Issue #2503. **Owner:** Issue #2714.

## Existing navigation authority and placement

- Business domain: **Finance, accounting, receivables, treasury and reports**.
- Persona: existing global `FINANCE` role, plus distinct store-scoped `RETAIL_FINANCE` for B2C. An `ACCOUNTANT`, `AR_OFFICER` or `TREASURY_MANAGER` assignment has separate built-in required-module and finance approval restrictions; never equate these roles solely by label.
- Canonical navigation: `AdminNavigation::groupsFor` with `AdminSidebarManager` and `backend/config/admin_sidebar.php`. Existing `finance` and `analytics` domains contain treasury master and reports; platform `erp` hub remains an existing authorized cross-domain entry, not a new finance menu. Canonical routes: `admin.treasury.master.index`, `admin.reports.index`, `admin.erp.finance.index` and `admin.erp.index`.
- Existing controllers: `TreasuryMasterController` uses `OperationalTenantScope::allowedStoreIds` and `assertStore`; `ReportController` uses tenant-scoped report permissions; `FinanceWorkbenchController` checks the finance module and per-context `finance.view` independently. Sidebar visibility is NOT a grant.
- Module readiness: `AdminNavigation::routeItemEnterpriseModule` reads `ModuleOperationalReadiness`, module lifecycle and `ModuleEntitlementResolver`. An uncertified finance or treasury module cannot appear as an operational entry even if its role carries `finance.view`. An authorized Platform Owner diagnostic link is not an operational workbench.
- Financial SoD: `FINANCE` holds separate read/post authority but not `finance.approve`; Treasury approval uses distinct authority. A role name is not sufficient to bypass approval or entitlement.

## Automated acceptance evidence

New `backend/tests/Feature/V2FinanceSidebarPersonaContractTest.php` creates synthetic B2B and B2C stores and users under `RefreshDatabase` and seeded canonical roles. It tests:

1. For **Arabic and English**, global Finance role permissions and real visible Treasury/Reports named routes resolve successfully via HTTP; non-finance admin, notifications, procurement and WMS entries are unavailable.
2. With intentionally absent module operational certifications, ERP Finance/Treasury operational links remain hidden even when the actor retains `finance.view`; this is a fail-closed readiness proof.
3. A B2C-only `RETAIL_FINANCE` assignment yields only its owned store in the real `OperationalTenantScope`, does not expose global platform ERP/treasury Sidebar entries, rejects direct platform ERP access and rejects a forged treasury-master `store_id` from a foreign store. A roleless actor is also denied.
4. The inspection does not mutate finance ledgers, invoices, stock, bank accounts, role grants or audit data. No production code or routes are changed.

This is intentionally additive proof over the real controller/role/module sources, **not** a duplicate Sidebar or fabricated finance feature. The existing RBAC, procurement, AP, treasury maker/checker and finance posting suites remain authoritative for transaction-level SoD. Disabled/uncertified modules must never be made visible just to satisfy this test.

## App and API impact

| Surface | Actual impact |
|---|---|
| Backend / Dashboard | New read-only Laravel Feature tests of existing finance Sidebar and HTTP boundaries; no production source change |
| Customer | No Flutter, checkout, invoice, wallet or customer REST API contract change |
| Driver | No Flutter, wallet/collection/dispatch/delivery/API change; B2C remains DRIVER ONLY |
| Van | No Flutter, wholesale/visit/collection/routing/API change; B2B remains VAN ONLY |

## Required CI, staging and remaining acceptance

Before merge, **exact final PR HEAD** must pass Repository Policy, V2 Isolation, PHP lint, Pint, PHPStan, Composer security, OpenAPI, localization AR/EN, full Laravel, MySQL/Redis fresh installation/upgrade/recovery and terminal `required-ci-gate`. Fix any red job in the SAME branch/PR and rerun; no bypass.

Independent **isolated V2 staging** must verify at 320/360/390/412/430/768/1024/1280+ px in AR RTL and EN LTR: keyboard and screen-reader traversal; Finance/Accountant/AR/AP/Treasury personas, certified and uncertified modules, platform/B2B/B2C store scope and forged direct HTTP requests, approval separation, no dead links, legacy saved Sidebar layouts, and concrete transaction-level maker/checker/credit/ledger audit evidence.

The test-only integration is **not** owner signoff for end-to-end accounting or ERP production readiness. Keep Issue #2714 OPEN until that independent acceptance is proven. Do not merge or modify `main`, release/tag, deploy to production, mutate live financial data or delete active worker branches.
