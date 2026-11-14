# FOODEX V2 #3515 — Van terminal visit direct-sale eligibility

## Source, branch and acceptance
- Atomic follow-up to #2605/P275, Van mobile #2739, visits #2738 and master #2503.
- One issue-scoped branch `fix/3515-v2-van-terminal-visit-sale-eligibility`; isolated PR base `Foodex_V2` ONLY. No main, release, version tag, deployment, production change or APK publication.
- Verified pre-existing defect: VanOrderController direct-sale endpoints checked actor/customer/store visit **existence**, without a visit-status predicate. A completed or closed historic visit therefore remained valid as sale authorization; no claim of exploitation.
- All four direct-sale HTTP paths require an actor-owned B2B visit at the effective current seller store in `planned` or `started` status. Historical `completed_with_order`, `completed_no_order`, `customer_unavailable` and `closed` visits deny 404 before quote, inventory reservation or order mutation. Existing Laravel commercial bridge retains price, finance, stock, idempotency and audit authority.
- `GET /api/v1/van/customers` continues to include historical customers for **collections**. It adds `can_direct_sell`, computed from the actor's own B2B current-store planned/started visits in one read query (never trusting any shared-Van metadata alone). The Van catalog projects only B2B customers whose exact server boolean is `true`, and resets a revoked/terminal draft/cart/quote. Missing/malformed/forged string values fail closed.
- No database migration, Sidebar route, Dashboard layout, new locale string or change to invoice/collection endpoints.

## Cross-runtime impact
| Surface | Classification | Explanation |
| --- | --- | --- |
| Backend API | **Affected** | Tightened existing sale authorization, additive customers boolean; OpenAPI synchronized |
| Dashboard | No new UI | Canonical Field Operations/Orders remain; #2605 still owns complete P275 Dashboard/ERP acceptance |
| Van Flutter | **Affected** | Sale picker and draft reset; #2739 linked; wallet customer history retained for collection |
| Customer Flutter | No contract impact | Customer-facing order endpoints unchanged |
| Driver Flutter | No contract impact | B2C Driver ownership and routing unchanged |

B2B=VAN ONLY; B2C=DRIVER ONLY. No role elevation or cross-store exemption.

## Tests / safety evidence
- `backend/tests/Feature/V2VanTerminalVisitDirectSaleTest.php`: authentic active Van actor, source B2B customer, current assigned Wholesale warehouse/store. Planned and started customer flag plus catalog/order-options allowed. Four terminal statuses still returned as customer history but rejected on catalog/options/quote/create. Another actor's open visit never grants new direct-sale authority.
- `apps/van_app/test/van_direct_sales_scope_test.dart`: active Wholesale selection retained; ended/revoked/missing flag clears customer/cart/quote.
- `apps/van_app/test/van_direct_sale_eligibility_http_test.dart`: actual MockClient HTTP JSON parses strict `true`; false/missing/string/b2c are not permission.
- Existing `backend/tests/Feature/V2VanDirectSalesScopeTest.php`, `VanOrderControllerTest.php`, Flutter Van order tests and API security/AR-EN are regression gates.
- Required final exact PR-head Repository Policy, V2 Isolation, Required CI (full Laravel, Flutter Van, format, static analysis, security, localization, real MySQL/Redis install/upgrade/recovery) must finish terminal SUCCESS. Current document is SOURCE evidence only and **not a CI success claim**.
- Do not close #3515, merge or remove the branch on incomplete or red exact-head checks. Retain parent issues #2605/#2739/#2738/#2503 open even after this atomic leaf is integrated.
