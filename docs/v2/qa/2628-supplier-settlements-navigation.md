# FOODEX V2 #2628 / P298 — Supplier Settlements navigation

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P298 (Finance & Treasury); parent #2503.
- Isolated integration: `Foodex_V2` ONLY. Issue branch `feat/2628-v2-supplier-settlements-group`.
- No `main` PR, production deployment, tag, release or APK publication.

## Route, business and placement decision

| Field | Resolution |
| --- | --- |
| Domain / entity | Finance & Treasury → supplier Accounts Payable (AP) documents and outstanding allocations |
| Persona / goal | Authorized B2B Finance / platform financial operator; discover AP documents, aging/outstanding and settlement context |
| Existing parent/route | `admin.erp.finance.index?store_id={authorized-store}&tab=payables` → `FinanceWorkbenchController::index` → `AccountsPayableService::paginate/aging` |
| Business operation | Existing `AccountsPayableService::createAllocation/postAllocation` supports audited, idempotent AP settlement lifecycle at service layer. This PR does not invent a new posting route or broaden a maker/checker permission. |
| Placement | **Reuse** the existing Finance Workbench and its `payables` page tab. Add only requested P298 Sidebar parent group; NO second AP workspace/screen/ledger. |
| Navigation | `SIDEBAR` group `supplier_settlements`, one canonical child key `erp_payables`, route `admin.erp.finance.index` with authorized store and `tab=payables`. Existing `receivables` group retains `erp_receivables`; `finance` retains `erp_finance`; `treasury` retains `erp_treasury`. |
| Data density / frequency | Existing paginated Finance Workbench AP list, filters and aging; no changed data renderer. |
| Permissions | Effective `finance.view` at exact store; `finance.ap.read` LEGAL_ENTITY capability; existing platform ERP admission; tenant/channel and live store scope; installed enabled & exact-certified Finance module readiness. |
| Forbidden / unavailable | Empty group filtered by canonical `AdminNavigation`; direct HTTP remains independently protected by authentication, financial controller scope and `EnsureErpOperationalReadiness`. No independent Sidebar bypass. |
| AR/EN, RTL/LTR, responsive | `admin.nav_groups.supplier_settlements` added to both dictionaries; existing shared responsive sidebar/off-canvas (320–1280px) and Finance Workbench preserved; no new CSS, input or focusable interaction. |
| Back/fallback | Existing Finance Workbench tabs and canonical admin navigation. No dead deep link. |

## Security and test evidence

The application computes `receivablesNavigationItems` **once**, then partitions only already-authorized items by the exact existing keys `erp_receivables` / `erp_payables`. Neither registration nor group membership grants an action or modifies an account. Existing protected `FinanceWorkbenchController`, `AccountsPayableService`, `FinanceFoundationService`, module lifecycle/readiness, `ModuleEntitlementResolver`, organization/legal-entity store mapping and maker/checker contracts are unchanged.

New `V2SupplierSettlementsGroupNavigationTest` covers:
1. Registry and canonical AP group exactly once; Finance/Receivables sibling isolation and real HTTP destination.
2. Authorized B2B Finance actor in a certified module, AP entitlement and legitimate store; AR/EN and RTL/LTR page.
3. Roleless user and explicit global `finance.view` DENY; group absence and direct HTTP 403.
4. Uncertified module; group absence and direct HTTP 403.
5. No finance posting, supplier payment or mobile API mutation.

### Cross-app impact

| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend API | NOT AFFECTED | No `routes/api.php`, public API, JSON contract, identity, finance mutation service, database migration or event change |
| Dashboard | AFFECTED | Only existing authorized financial sidebar parent/registry and AR/EN labels; existing Finance Workbench route untouched |
| Customer | NOT AFFECTED | No mobile endpoint, pricing, cart, invoice, order/credit or notification contract change |
| Driver | NOT AFFECTED | No driver endpoint, dispatch/wallet/collection, status, offline or proof contract change; B2C=DRIVER ONLY retained |
| Van | NOT AFFECTED | No Van endpoint, visits, route, stock/consignment, custody, settlement or offline contract change; B2B=VAN ONLY retained |

## Scope and acceptance boundary

**P298 is a navigational parent**. The visible AP destination is a genuine read/aging workspace and services already implement underlying allocation/post logic. This change does **not** expose a new AP settlement form/mutation. If P298 acceptance specifically requires a new user-facing payment allocation/approval workflow, the source scope is **NOT COMPLETE** from this PR alone; implement and validate it through the original issue/PR, coordinating shared Finance Controller, route, Blade and maker/checker owners. A browser/staging/manual V2 UAT under #2503/#2750 may be deferred; source, automated security and exact-head CI cannot.

Before merging check same-branch latest SHA, PR base `Foodex_V2`, required Repository Policy, FOODEX V2 Isolation Gate, Required CI (full Laravel, Pint/PHPStan/security/OpenAPI, localization and MySQL/Redis install/upgrade/recovery as applicable), and peer file leases. Do not close the issue on partial acceptance.
