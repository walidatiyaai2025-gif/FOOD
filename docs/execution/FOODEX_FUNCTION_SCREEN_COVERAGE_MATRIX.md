# FOODEX Function ↔ Screen Coverage Matrix

Issue: #1129  
Parent mission: #1112  
Final gate: #1122  
Status: **PASS — functional screen/journey convergence proven**

## Integrated evidence baseline

- Integrated runtime code baseline on main before this documentation-only convergence: `06fa6627275b05539a4b37deab169311a0479135`.
- Exact integrated PR head with W1-W8 already merged and cross-app runtime smoke: `3182ba027dfdf3ab05445693e1311f8df2309441` (#1134).
- W7 exact head: `aea748c7710809161d0ac043a57eb30e1bc8a292` (#1133), Required CI Gate + Repository Policy + UI Visual QA + Update Bundle + Store Submission Readiness: PASS.
- W8 exact head: `761ba105dec1bc165716009f1a4938222c5e6632` (#1132), Required CI Gate + Repository Policy + Mobile Screenshot Capture + UI Visual QA + Update Bundle + Store Submission Readiness: PASS.
- Cross-stream exact head: `3182ba027dfdf3ab05445693e1311f8df2309441` (#1134), Required CI Gate + Repository Policy + Update Bundle + Store Submission Readiness: PASS.
- #1122 must re-run the integrated acceptance on its own exact head before declaring INTEGRATED_RUNNABLE; this matrix is consumed by that same-head gate.

No row below is UNKNOWN, UNOWNED, PARTIAL or FAIL.

## Acceptance contract

A user-facing function is PASS only when the chain is present on the authoritative production path:

```
Function -> Authorized entry point -> Canonical route -> Real screen
         -> Authoritative data/service -> Executable action
         -> Persisted result / truthful feedback -> Related-screen reconciliation
```

## Merchant Intelligence critical journey

| Function ID | Actor / role | Canonical route + real screen | Authority / executable result | States, locale, responsive | Evidence | Status |
|---|---|---|---|---|---|---|
| merchant.store_today | Retail Owner / Store Admin | `/admin/b2c/dashboard` → canonical B2C workspace + Merchant Intelligence partial | `RetailMerchantDashboardService` aggregates authoritative inventory/reorder/finance; KPI/detail drill-down stays in canonical workspace | AR/EN sources; Dashboard responsive contract | `backend/tests/Feature/RetailMerchantDashboardTest.php` | PASS |
| merchant.stock_risk | Retail Owner / Store Admin | Dashboard attention + `focus_product` exact product filter | W3 inventory facts + W4 recommendation facts; exact product context is selected rather than generic redirect | Loading/empty handled by existing workspace; RTL/LTR via admin language sources | `RetailMerchantDashboardTest::test_canonical_dashboard_and_inventory_drill_down_are_wired_in_place` | PASS |
| merchant.recommendation.why | Retail Owner / Store Admin | Recommendation row on Merchant Intelligence dashboard | Deterministic `reason_facts` / explanation payload from reorder engine | No LLM numeric authority; localized presentation | `RetailMerchantDashboardTest::test_owner_dashboard_combines_retail_intelligence_with_owner_only_wholesale_finance`; `RetailReorderIntelligenceTest.php` | PASS |
| merchant.replenishment.detail | Retail Owner / Store Admin | Dashboard recommendation → exact inventory/product context | Mapping, stock, velocity, cover, lead-time, executable quantity and expected cost originate from W2/W3/W4 services | Cold-start/missing mapping/availability-limited states are explicit | `RetailWholesaleReplenishmentTest.php`; `RetailInventoryIntelligenceTest.php`; `RetailReorderIntelligenceTest.php` | PASS |
| merchant.wholesale.account | Linked Retail Owner only | Owner-only Wholesale account card on canonical dashboard; Customer authority also maps B2B account routes | Canonical user → authoritative B2B customer/account/ledger; Retail staff finance stays hidden/server-forbidden | AR/EN; responsive dashboard | `B2bAccountLifecycleTest.php`; `RetailMerchantIdentityIsolationTest.php`; owner/manager cases in `RetailMerchantDashboardTest.php` | PASS |
| merchant.purchase_plan | Linked Retail Owner only | Dashboard Suggested Wholesale Plan form → `admin.b2c.merchant-intelligence.cart` | Server recomputes W4, live price/tier/MOQ/increment/availability and writes existing B2B cart only | Stale price/budget error is visible; quantity edit/remove supported; no auto-submit | `RetailMerchantDashboardTest::test_owner_can_apply_reviewed_plan_idempotently_to_existing_wholesale_cart` | PASS |
| merchant.purchase_plan.constraint | Linked Retail Owner only | Same plan surface | Deterministic priority, credit/budget and existing-cart constraints; stale repricing rejected | Error feedback on stale budget; owner-only mutation | `test_plan_reprices_at_mutation_time_and_blocks_stale_budget`; `test_retail_manager_cannot_mutate_owner_wholesale_cart` | PASS |
| merchant.b2b.cart | Authorized B2B buyer | Existing canonical B2B cart from Customer route authority | Target-quantity semantics prevent duplicate lines; existing `carts/cart_items` authority preserved | Customer production route authority covers B2B cart/checkout | `UI_ROUTE_AUTHORITY.json`; W7 idempotent cart feature test | PASS |
| merchant.checkout | Authorized B2B buyer | `/b2b/cart` → `/b2b/checkout` → existing MultiStore production flow | Existing B2B pricing/order semantics remain authoritative; W7 stops before auto-submit | Existing Customer auth/error/runtime contracts retained | `UI_ROUTE_AUTHORITY.json`; #1134 Customer runtime smoke on integrated tree | PASS |
| merchant.purchase_history | Authorized B2B buyer | `/b2b/orders`, `/b2b/orders/:id` | Existing authoritative Wholesale order/history screens; exact order drill-down retained | AR/EN and Customer runtime evidence | `UI_ROUTE_AUTHORITY.json`; `PlatformAnalyticsRolloutContractTest.php` exact order path assertion; Customer tests in #1132/#1134 | PASS |
| merchant.inventory.reflection | Retail Owner | Retail receipt lineage → inventory intelligence → dashboard refresh | `RetailWholesaleReplenishmentService` persists receipt lineage; W3/W4 read authoritative post-receipt stock | Idempotent receipt, invalid mapping/tenant rollback | `RetailWholesaleReplenishmentTest.php`; W3/W4 feature suites | PASS |
| merchant.analytics.drilldown | Authorized Dashboard/Customer/Driver/Van actors | Canonical analytics surfaces only | B2B sales→reports, order distribution→orders, Customer order→exact detail, Van analytics→Customers/Wallet/Receipts/Remittance | Shared visualization states, AR/EN, responsive/mobile | `PlatformAnalyticsRolloutContractTest.php`; Customer/Driver/Van widget tests from #1132; Mobile Screenshot Capture PASS | PASS |

## Platform functional convergence

| Function ID | Surface | Normal entry / authoritative context | Actionability and reconciliation | Evidence | Status |
|---|---|---|---|---|---|
| customer.wholesale.analytics | Customer | Normal B2B dashboard/report/order navigation from `UI_ROUTE_AUTHORITY.json` | Purchase/category analytics use shared charts and exact order detail path | `apps/customer_app/test/b2b_journey_test.dart`; #1132 CI | PASS |
| driver.workload.analytics | Driver | Canonical Driver home/deliveries | Workload chart uses assignment state and preserves per-status operational navigation | `apps/driver_app/test/navigation_test.dart`; #1132 CI; #1134 Driver smoke | PASS |
| van.cashflow.analytics | Van | Canonical Van dashboard | Customers/Wallet/Receipts/Remittance callbacks open real operational screens | `apps/van_app/test/app_test.dart`; `PlatformAnalyticsRolloutContractTest.php`; #1132/#1134 CI | PASS |
| van.order_route_stock.analytics | Van | Canonical Orders / Routes / Catalog | Outcome/productivity/availability charts live on operational screens and inherit refresh/error behavior | `apps/van_app/test/app_test.dart`; #1132 CI | PASS |
| dashboard.b2b.analytics | Dashboard | Canonical B2B workspace | Sales analytics drill to Reports; order distribution drills to Orders | `PlatformAnalyticsRolloutContractTest.php` | PASS |
| crossapp.route_authority | Customer / Driver / Van / Dashboard | Registered production authorities only | No duplicate hidden production renderer is accepted by repository policy | `docs/execution/UI_ROUTE_AUTHORITY.json`; #1100; Repository Policy PASS on #1132/#1133/#1134 | PASS |

## Connected invariants retained on the integrated tree

| Invariant | Proof | Status |
|---|---|---|
| One canonical Retail Owner ↔ Wholesale Buyer identity; Retail staff cannot inherit owner finance | W1 feature tests + owner/manager dashboard tests | PASS |
| Retail↔Wholesale mapping and receipt lineage are tenant-scoped and idempotent | W2 replenishment tests | PASS |
| Inventory velocity/cover/aging/risk is deterministic and timezone-safe | W3 feature suite | PASS |
| Lead-time/reorder quantity is deterministic, MOQ/increment/availability aware | W4 feature suite | PASS |
| Suggested plan reprices at mutation time and cannot overrun current budget/credit | W7 feature tests | PASS |
| Existing invoice/ledger/pricing/order semantics are reused, not replaced | W1/W4/W7 implementation contracts and tests | PASS |
| Shared Web + Flutter visualization system is used instead of parallel chart systems | W5/W8 contract tests | PASS |
| Customer / Driver / Van production navigation remains authoritative | `UI_ROUTE_AUTHORITY.json`, #1100, #1132/#1134 runtime tests | PASS |
| Dispatch/fleet contracts remain registered on the converged integrated tree | `FinalIntegrationGate1103Test.php` + #1134 Required CI | PASS |

## Release-blocker scan

Required in-scope rows with status PARTIAL / FAIL / UNKNOWN / UNOWNED: **0**.

Backend/API-only Merchant Intelligence user-facing functions: **0 identified**.  
Placeholder/mock-only required Merchant Intelligence screens: **0 identified**.  
Dead required Merchant Intelligence CTA/drill-downs in audited scope: **0 identified**.  
Undocumented deep-link-only required Merchant Intelligence functions: **0 identified**.

#1129 is therefore **PASS** and may be consumed by #1122. #1122 still owns the final same-head G0-G13 gate and must not publish a release.
