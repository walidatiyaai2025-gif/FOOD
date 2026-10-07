# UIUX v4.2 Recovery — Customer Evidence Manifest (#1038)

Mission: **#1034 — UIUX-V42-RECOVERY**  
Owner: **#1038 — Customer App full v4.2 compliance sweep**  
Canonical branch: `feat/1038-customer-v42-full-sweep`  
PR: **#1049**

This manifest records owner-lane source, automated-test and screenshot-selector evidence. It does **not** mark central matrix rows PASS; #1042/#1043 remain the independent runtime/convergence gates.

| Row | Source evidence | Automated evidence | Runtime selector/evidence |
|---|---|---|---|
| MC01 | Customer UI V3 shared shell/components; compact Orders/Profile/Catalog/Commerce surfaces | `customer_ui_v3_design_system_test.dart`, `platform_marketplace_home_test.dart`, `customer_orders_ui_v3_test.dart` | AR/EN screenshot matrix across Retail/B2B routes at 430x932 |
| MC02 | compact date/filter controls in Customer Orders and B2B report/product filters | `customer_orders_ui_v3_test.dart` compact hierarchy; B2B report/product widget coverage in `b2b_journey_test.dart` | B2B reports/top products + B2C orders screenshots AR/EN |
| MC03 | one-line order/reference identifiers in Customer Orders | `customer_orders_ui_v3_test.dart` — `order identifiers stay single-line...` | B2C/B2B populated Orders screenshots AR/EN |
| MC04 | compact order cards/rows; one green ellipsis where record row actions apply | `customer_orders_ui_v3_test.dart` — single green ellipsis assertion | populated Orders/Profile/Favorites screenshots AR/EN |
| MC05 | Orders polling + resume + stale timestamp; Retail account/favorites/addresses/notifications/home/catalog/cart/checkout lifecycle refresh; Wholesale Home/Catalog/Product/Cart/Checkout/Orders/Detail resume refresh | `customer_orders_ui_v3_test.dart`, `b2b_journey_test.dart` resume test, `customer_v42_dynamic_refresh_contract_test.dart` full wholesale lifecycle guard | loaded/offline/order-tracking screenshots plus #1042 interactive verification |
| AC01 | `unified_customer_auth_screen.dart` visible role identity using localized `Customer App / تطبيق العميل` | `unified_customer_auth_test.dart` visible EN + Arabic RTL assertions | B2B/B2C login screenshots AR/EN |
| AC02 | secure session persistence, Remember Me and biometric unlock | `customer_auth_persistence_test.dart`, `unified_customer_auth_test.dart` | login screenshot selectors expose Remember Me + biometric controls |
| IC01 | B2B invoice detail renders FOODEX/company identity/logo path plus authoritative seller/customer/items/payments/ledger/totals | `b2b_journey_test.dart` invoice reconciliation/branding coverage | `01_Mobile/B2B_Customer/09_تفاصيل_الفاتورة__populated__{ar,en}.png` |

## Official AR/EN screenshot harness

`apps/customer_app/test/screenshot_evidence_test.dart` runs every listed capture case in both `Locale('ar')` and `Locale('en')`, replacing `__ar.png` with the active locale code. It covers:

- Customer login/entry.
- Retail home, offers, products, product detail, cart, checkout, order tracking.
- Profile, addresses, categories, favorites, orders, notifications, settings.
- Wholesale home, product detail, cart, checkout and orders.
- B2B dashboard, purchase reports, top products, invoices, account statement, order list/detail, product/catalog, cart and profile.
- Invoice detail with configured FOODEX/company identity.

## Acceptance boundary

- Owner-lane implementation, route audit and test evidence are complete here.
- Central rows remain `OPEN` until #1042 reviews the real integrated runtime and #1043 converges the matrix.
- Closing #1038 must not be treated as a substitute for #1042/#1043 PASS.
