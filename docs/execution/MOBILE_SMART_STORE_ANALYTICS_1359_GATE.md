# #1359 — Mobile Smart Store Analytics final convergence gate

Parent: #1354

This document is the repository-visible convergence manifest for the final W5 gate. Child-Issue closure is not accepted as proof by itself; the rows below bind each release-blocking requirement to integrated source, focused automated evidence, and exact child source lineage.

## Frozen child source lineage

| Lane | Issue / PR | Exact validated source |
|---|---|---|
| W1 backend contract | #1355 / #1361 | validated head `4dc9ccd6051387a2f78667aa0b76fa11610021e3`; merged main commit `21e1d8e899275d709eb661f845f5cc81e9bb0cda` |
| W2 route/client/localization | #1356 / #1363 | head before merge `44991fc30c6debdda1526f7f5c0597c6c89ac980`; merged main commit `96310270caa96f4770587268475ce4c239e0af96` |
| W3 Business Dashboard launcher/branding | #1357 / #1360 | head `f9d6cc6475aac63da459b1cc05188422d97f8838`; merged main commit `b4d483557b68e5f8cf4640c45f5c34b21f5bea5e` |
| W4 compact Store Analytics screen | #1358 / #1362 | exact integration head `fcf689e4b91fe0366253b58b97990510fceb1fd7` |

The W5 PR/Issue terminal record is the authority for the final W5 exact head and exact-head CI conclusion after W4 is merged to protected `main`.

## Independent acceptance matrix

| Requirement | Integrated evidence | Gate |
|---|---|---|
| Dashboard-uploaded `stores.logo_path` reaches canonical `logo_url`, Business Dashboard branding and Store Analytics presentation | `backend/tests/Feature/MobileStoreAnalyticsApiTest.php::test_authorized_retail_merchant_receives_exact_egp_store_analytics_contract`; `apps/customer_app/test/business_dashboard_smart_analytics_integration_test.dart`; `apps/customer_app/test/business_dashboard_smart_analytics_launcher_test.dart`; W4 screen/logo fallback tests | PASS when W5 exact-head CI is green |
| Retail owner/store authorization is server-authoritative | backend authentication/entitlement and exact authorized-store tests in `MobileStoreAnalyticsApiTest.php` | PASS when W5 exact-head CI is green |
| Cross-store access is forbidden and multi-store ownership requires explicit Retail selection | `test_multi_store_context_is_explicit_and_cross_store_requests_are_forbidden`; Customer API selector-required coverage | PASS when W5 exact-head CI is green |
| Wholesale `store_id` cannot collide with or override Retail analytics context | `test_legacy_wholesale_store_id_cannot_override_server_resolved_retail_context`; `apps/customer_app/test/store_analytics_route_test.dart`; `apps/customer_app/test/store_analytics_api_test.dart` | PASS when W5 exact-head CI is green |
| Launcher unread badge lifecycle is deterministic | backend `test_seen_insight_version_clears_new_badge_without_mutating_server_state`; Business Dashboard integration test verifies version echo after opening and badge clear | PASS when W5 exact-head CI is green |
| Launcher pulse/glow is accessibility-safe and reduced motion is static | `apps/customer_app/test/business_dashboard_smart_analytics_launcher_test.dart` | PASS when W5 exact-head CI is green |
| Analytics money is authoritative EGP and non-EGP identities fail closed | backend exact EGP contract; Customer API invalid-currency test; W4 read-model adapter rejects non-EGP | PASS when W5 exact-head CI is green |
| Daily trend and deterministic insight facts are displayed without client finance/inventory recomputation | W1 contract tests; W2 normalization tests; `apps/customer_app/test/store_analytics_screen_test.dart` | PASS when W5 exact-head CI is green |
| AR/EN, RTL/LTR and 320/360/390/412/430 are overflow-safe | W3 integration/launcher width matrix; W4 screen width matrix; Required Mobile visual evidence workflow | PASS when W5 exact-head CI is green |
| Populated, empty, stale/error, no-logo, no-entitlement and multi-store states are explicit | backend empty/stale tests; Customer API no-entitlement/selector tests; W3 logo fallback; W4 presentation-state tests | PASS when W5 exact-head CI is green |
| Exact drill-down context and return navigation remain Retail-scoped | W2 route/safe-return tests; W3 canonical launcher route; W4 exact drill-down callback tests | PASS when W5 exact-head CI is green |
| No duplicate analytics fan-out contract is introduced | W3 integrated dashboard test records the canonical analytics request; W2/W4 consume one typed read model and no alternate endpoint | PASS when W5 exact-head CI is green |
| Release/version and packaged mobile identity remain valid | FOODEX Required CI Gate Android release validation, Repository Policy, Store Submission Readiness and FOODEX Update Bundle on the exact W5 PR head | PASS only on exact W5 head |

## Final gate rule

W5 is complete only when:

1. #1358 / #1362 is merged into protected `main`;
2. this W5 branch is reconciled onto that resulting main lineage with no child implementation duplicated;
3. the W5 PR diff contains only final convergence/evidence or integration-defect fixes owned by #1359;
4. FOODEX Required CI Gate is green on the exact W5 head, including Customer Android release validation and required mobile visual evidence;
5. Repository Policy, Store Submission Readiness and FOODEX Update Bundle are green on the same head;
6. the #1359 terminal Issue comment records the exact W5 head, child SHAs, PR number, CI conclusions and merge SHA.

Any FAIL, UNKNOWN, skipped-required evidence, authorization leak, Retail/Wholesale context collision, non-EGP analytics identity, or stale child lineage blocks merge.
