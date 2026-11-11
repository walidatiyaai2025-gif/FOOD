# FOODEX V2 P306 — Operational Reports authorized route and real KPI contract

Issue: #2636 | Parent: #2503 | Sidebar matrix: `P306` (Reports & BI) | Actual DOCX paragraph: `P295` | Base branch: **`Foodex_V2` ONLY**.

## Placement and route authority

| Decision | Verified canonical implementation |
| --- | --- |
| Domain / entity | Reports & BI → operational order, delivery, stock summary |
| Actor / role | `SUPER_ADMIN` global Reports reader and `B2C_STORE_ADMIN` with active Retail store role; roleless actor denied |
| User goal | Inspect store/channel operational KPIs: order counts, delivered vs cancelled, recognized revenue, assigned deliveries and stock totals |
| Workflow | Recurring read-only dashboard monitoring; dense operational grid with KPIs and filters |
| Placement choice | **Reuse** existing Reports Center family `operations` — no new route, Sidebar leaf or duplicated renderer |
| Entry point | Analytics Sidebar → `reports_center` (global) or assigned-store `b2c_reports` (Retail), then existing `operations` family tab |
| Existing parent / route | `AdminNavigation::groupsFor` Analytics; `admin.reports.index?report=operations` and `admin.b2c.module?module=reports&store_id=<authorized>` |
| Controller / data authority | `ReportController@index` → `ManagementReportService::run` → `operationsReport`; `admin.reports` Blade, no second report implementation |
| Permission / channel / store | `reports.view` and `OperationalTenantScope`/store-role guard; explicit global/store DENY; Retail forced to B2C; store selection required for scoped users |
| Export | `admin.reports.export` has independently enforced `reports.export` permission; a visible report never grants exporting |
| Entitlements / readiness | Existing core reporting family requires no ERP BI activation; `erp_bi` remains separately gated by module readiness, entitlement and runtime policies. No unready module linked or enabled |
| Desktop / tablet / mobile web | Existing page toolbar, AR/EN labels, responsive filters, reporting grid and horizontal family navigation; no new CSS/markup or touch targets |
| RTL/LTR | Existing `reports.families.operations` translation and locale-specific `dir` rendered by the canonical Reports Center |
| Navigation back/fallback | Existing Analytics/Reports Center and authorized Retail workspace links; forbidden/missing scope returns HTTP 403 or store validation, not a dead link |
| Empty/error states | Existing no-rows report state, zero KPIs, validation error for absent store, explicit 403 for denied store/channel |

The P306 source is an **operational reporting** requirement. It is not a finance-ledger report, a vendor inventory ledger or a certified ERP BI workspace. Related leaf #2534 tests supervisory report families, #2640 customer-service reports and #2641 Analytics grouping. This P306 leaf adds independent **real operations metric and privacy** coverage without duplicating or touching their code.

## Acceptance evidence: automated real DB and HTTP

Added `backend/tests/Feature/V2OperationalReportsAuthorityTest.php`, using seeded reference data, synthetic stores, real legacy Customer and orders, actual Laravel HTTP / navigation:

1. Super Admin can discover exactly one Reports Center link and open `report=operations` in Arabic RTL and English LTR. Two synthetic Retail stores give **3 orders**, **125 recognized revenue** (cancelled 999 explicitly excluded), two store rows, delivered/cancelled status counts and zero delivery assignments with no assignments present. Exact numbers verified from real service output, not mocked.
2. Roleless actor gets no authorized Reports Center or Retail report link and cannot request the real operational report or PDF export: HTTP 403 in both locales.
3. Retail store manager can discover assigned `b2c_reports` and open own store Reports module/real operational grid. Missing store is validated, foreign store 403/404, B2B channel 403, own-store KPI includes **2 orders / 50 recognized revenue** only and excludes the 750 foreign-store order, both locales.
4. Explicit `reports.view` store-scoped DENY removes effective Retail Reports navigation and makes direct operational report HTTP 403.

The test fixture honors legacy `orders.customer_id` non-null requirement; no migration, production data, direct finance write, entitlement grant, generated release or deployment involved.

## Application and delivery impact matrix

| Surface | Classification | Evidence |
| --- | --- | --- |
| Laravel Backend / Dashboard | Additive behavioral/authorization regression, no runtime modifications | Only new Laravel feature tests against existing report service + existing authorized Dashboard; existing API unchanged |
| Customer Flutter | **Not affected** | No Customer app code, API response, identity/account, order/checkout, storage/notification or UI route changed |
| Driver Flutter | **Not affected** | No Driver code/API, B2C assignment/status/proof, collection or mobile routing change |
| Van Flutter | **Not affected** | No Van code/API, B2B van visits/routes/orders/stock or offline behavior change |
| Storage/cache/queue/mail | Not affected | No runtime code, migration or configuration changed |

The B2B=VAN ONLY, B2C=DRIVER ONLY dispatch boundary remains untouched. No mobile follow-up issue is needed for a test/documentation-only change.

## Gate and closure conditions

Required on the **exact final PR head**: Repository Policy; FOODEX V2 Isolation Gate; FOODEX Required CI Gate (Laravel feature + full suite, PHP syntax, Pint, PHPStan, Composer security/OpenAPI, Arabic-English localization and real MySQL/Redis fresh-install, supported-upgrade, backup/recovery acceptance as applicable). An earlier green run on a different SHA does not qualify. Fix any red result on the same branch/PR, never create a duplicate lane.

After all required latest-head checks pass and PR scope/base/mergeability are verified, squash-merge **only into `Foodex_V2`**, verify merge SHA and base comparison, document CI links and close #2636 only when implementation coverage is evidenced. **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** per owner #2503; no claim of staging/UAT/browser or production verification.

**Explicit prohibition:** no merge to `main`, no release/tag, no production/cPanel deployment or version promotion without a separate written owner approval. Master #2503 remains open.
