# V2 #2621 / P291 — Treasury & Banking authorized business group

## Source and placement

- Source `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Sidebar item P291 `الخزينة والبنوك`; parent #2503.
- Domain Finance & Treasury, entity treasury master data and enterprise treasury operations; role B2B platform Finance/Treasury authorized staff; user goal discover Treasury/bank actions under an identifiable business domain.
- Placement: **Reuse → Extend** the existing authoritative Sidebar group registry and `AdminNavigation::groupsFor`. Navigation type **SIDEBAR GROUP** of authorized existing children (not a new page or a new authorization authority).
- Canonical entry points: `treasury_master` → `admin.treasury.master.index` for cashboxes/bank-account master data; `erp_treasury` → `admin.erp.treasury.index` for enabled and entitled enterprise Treasury workflows.
- `erp_finance` remains a separate `finance` group child, labeled `المالية والمحاسبة` / `Finance & Accounting`; the new Treasury group no longer repeats Treasury in the Finance header. No duplication in the new Treasury group, no new route, no capability granted and no forced enablement of Treasury.
- `backend/config/admin_sidebar.php` now registers `treasury` between Finance and Receivables and moves Treasury registry items, keeping canonical protected `treasury_master` pinned and allowing existing authorized saved-layout behavior for non-protected entries.
- Labels: `admin.nav_groups.treasury` translated as `الخزينة والبنوك` (AR) and `Treasury & Banking` (EN), with no raw English wording in AR. Responsive Sidebar uses existing desktop/mobile web, 44px touch controls, focus/keyboard, RTL/LTR and permission-projected rendering.

## Access and security

- Existing `routeItemScoped` for `treasury_master` preserves `finance.view`, dashboard authentication, wholesale/retail tenant scope, store read authority and HTTP checks. Retail without effective `finance.view` sees no Treasury group and receives HTTP 403; a Retail store actor granted `finance.view` sees Treasury Master in the group and can open **only** their authorized store. A forged sibling-store query receives 404. This removes preexisting direct-URL-only discoverability for a genuinely authorized Retail Finance actor without broadening server permissions.
- Existing `routeItemEnterpriseModule` for `erp_treasury` preserves Treasury module enabled/readiness and Finance permission; its HTTP controller/service continue to perform context, entitlement, permission and maker/checker enforcement.
- Sidebar group contains only currently authorized children; an empty group is removed by the existing `groupsFor` filter. Changing or hiding a display group never adds a permission or relaxes a 403.
- This PR makes no treasury payment, bank statement, collection, GL journal, financial account, or tenant row mutations. Existing saved role/sidebar overrides and audit semantics are retained.

## Verification

`V2TreasuryBanksGroupNavigationTest` checks registry and authorization source of truth, exact authorized child route, no duplicate Finance group entries, disabled Treasury hiding, Retail no-permission denial, authorized same-store entry and sibling-store direct-route denial, and AR/EN translation parity. Existing `AdminNavigationAuthorizationAuditTest`, `AdminSidebarManagementTest` and Treasury module HTTP tests remain unchanged.

CI required on exact issue PR head: Repository Policy; V2 Isolation Gate; Arabic/English localization; Laravel syntax/Pint/PHPStan/security/OpenAPI; full Backend tests; MySQL/Redis installation-upgrade-recovery as applicable; terminal `required-ci-gate`. A passing build alone is insufficient.

## Customer / Driver / Van impact

| App | Effect | Reason |
| --- | --- | --- |
| Customer | Not affected | No Customer API, JWT/identity, customer finance/credit data or Flutter screens changed |
| Driver | Not affected | No B2C collections, dispatch, status or driver finance API modified; B2C DRIVER ONLY unchanged |
| Van | Not affected | No B2B remittance, route, van wallet, delivery, stock or Van app API modified; B2B VAN ONLY unchanged |

## Acceptance boundaries

P291 is a **business-group only** requirement, not a proposal to implement unready bank workflows. End-to-end operational capability (Treasury receipts, payments, bank reconciliation) remains with their own existing V2 issues. Validate mobile web and keyboard/RTL in final cross-persona UAT #2750. Keep #2503 OPEN pending comprehensive V2 source coverage. No main merge or production deploy.
