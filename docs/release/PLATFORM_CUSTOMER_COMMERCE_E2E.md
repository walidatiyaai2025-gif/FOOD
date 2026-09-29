# FOODEX 1.0.34 Platform Customer Commerce E2E Gate

Issue: #414  
Branch: `test/414-platform-customer-commerce-e2e`

This gate promotes the already merged #407-#413 work only when the same candidate commit passes backend, MySQL upgrade, Customer Android/iOS, Driver Android/iOS, runtime screenshot evidence, release identity checks, and the journey-specific evidence contracts below.

## Mandatory journey evidence

| # | Journey | Automated evidence |
|---|---|---|
| 1 | Retail A registration records origin Retail A, materializes Retail A profile, and activates Wholesale entitlement with the correct tier. | `PlatformCustomerRegistrationOriginTest::test_retail_origin_registration_materializes_source_retail_and_wholesale_with_store_default_tier` |
| 2 | Same login shops Retail A, keeps the order scoped to Retail A, and produces store-scoped order/invoice data. | `PlatformCustomerUnifiedOrdersTest`, `DashboardOrderManagementTest::test_retail_dashboard_quote_and_multiline_invoice_are_authoritative` |
| 3 | Same login shops Wholesale with assigned tier pricing and Wholesale-only order/invoice visibility. | `AuthoritativePricingQuoteTest::test_wholesale_quote_uses_assigned_tier_moq_increment_and_pack_rules`, `DashboardOrderManagementTest::test_wholesale_dashboard_quote_uses_selected_warehouse_and_tier_for_multiple_lines` |
| 4 | Opening Retail B materializes its retail domain without changing registration origin; Retail B commerce remains isolated from Retail A. | `PlatformCustomerRegistrationOriginTest::test_later_retail_materialization_does_not_change_registration_origin`, `PlatformCustomerUnifiedOrdersTest::test_platform_customer_order_detail_rejects_foreign_customer_and_store_channel_tampering` |
| 5 | Customer order history aggregates Retail A, Wholesale and Retail B with store/channel provenance and excludes foreign orders. | `PlatformCustomerUnifiedOrdersTest::test_platform_customer_orders_aggregate_wholesale_and_retail_with_store_provenance` |
| 6 | Dashboard creates multi-line Retail/Wholesale orders from authoritative quotes and supports invoice view/print/reissue paths. | `DashboardOrderManagementTest::test_retail_dashboard_quote_and_multiline_invoice_are_authoritative`, `DashboardOrderManagementTest::test_wholesale_dashboard_quote_uses_selected_warehouse_and_tier_for_multiple_lines` |
| 7 | Assigned driver receives only authorized delivery data, sees the issued invoice, follows backend-allowed status transitions, and can submit a note. | `DriverInvoiceNotificationTest::test_assigned_driver_receives_safe_invoice_and_note_timeline_only_for_own_assignment`, Driver Flutter `driver_journey_test.dart` |
| 8 | Driver status/note and invoice/payment operational events are scoped, deduplicated and deep-linked for authorized Dashboard users. | `DriverInvoiceNotificationTest::test_retail_operational_notifications_are_store_scoped_deduplicated_and_have_authorized_deep_links`, `test_replaying_same_driver_event_does_not_duplicate_unread_dashboard_event` |
| 9 | Cross-store/customer/order/invoice tampering does not leak data. | `PlatformCustomerUnifiedOrdersTest::test_platform_customer_order_detail_rejects_foreign_customer_and_store_channel_tampering`, `DashboardOrderManagementTest::test_b2c_manual_order_rejects_foreign_store_product_and_customer_ids`, `B2bFinanceTest::test_invoice_ownership_and_account_approval_are_enforced` |
| 10 | Price/tier/product changes after checkout do not mutate historical commercial snapshots. | `AuthoritativePricingQuoteTest::test_checkout_reprices_live_price_and_historical_snapshot_does_not_drift`, `test_wholesale_order_keeps_tier_and_price_snapshot_after_tier_changes` |
| 11 | Production-like MySQL upgrade preserves legacy customers/orders and safely reconciles the newer domains. | Backend CI `production-services` job and `tests/Deployment/MultiTenantUpgradeTest.php` |
| 12 | Arabic RTL and English LTR are validated across Customer and Driver apps; release builds are compiled for Android and iOS. | Customer/Driver reusable CI workflows plus localization and journey tests; runtime screenshot evidence workflow |

## Promotion rule

The top-level `FOODEX Platform Customer Commerce E2E` workflow is the release decision gate for #414. Its final `Platform Customer commerce release gate` job must be green. The generated `platform-customer-commerce-e2e-<sha>` artifact records the exact candidate commit, synchronized FOODEX version, and pass state for Backend, Customer, Driver and runtime evidence.

Do not close #414 or claim the unified commerce journey is release-ready while any dependency is red, skipped unexpectedly, or missing its evidence artifact.
