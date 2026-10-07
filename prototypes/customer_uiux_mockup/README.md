# FOODEX Customer UIUX Mockup Lab

This is a **design-only mirror of the current Customer App**, not a new product concept.

Current production-design reference:
- source lane: `feat/1038-customer-v42-full-sweep`
- routing source: `apps/customer_app/lib/core/routing/customer_routes.dart`
- Customer UI V3 tokens: `apps/customer_app/lib/core/theme/customer_ui_v3_tokens.dart`
- current retail shell: `apps/customer_app/lib/features/retail/customer_ui_v3/customer_retail_shell.dart`
- current persistent footer: `apps/customer_app/lib/shared/customer_persistent_footer.dart`
- current order status/tracking: `apps/customer_app/lib/features/customer_orders/customer_order_screens.dart`

## Mirror rule

The mockup must follow the ideas, information architecture, navigation and visible business capabilities already present in the current Customer App. It must not invent a replacement Customer product.

It is allowed to use fake display data because this is a mockup, but:
- no backend/API calls
- no real authentication
- no real checkout/payment
- no database writes
- no operational side effects

## FOODEX identity

The mockup follows the current Customer UI V3 palette:
- Deep Green `#00452F`
- Deep Green Strong `#003C2A`
- Lime `#9BE252`
- Lime Soft `#E7F8D7`
- Mint `#EDF7F1`
- Border `#DDE8E1`
- Ink `#17231D`

## Current-app behaviors mirrored

- Marketplace entry with Wholesale first and Retail store discovery
- unified Customer login/register + Remember Me + biometric concept
- Retail Home with banner, categories, offers and two-column product grid
- current persistent footer model:
  - Retail: Home / Products / Cart / Orders / Profile
  - Wholesale: Home / Shopping / Orders / Invoices / More
- product details, favorites, cart and checkout
- customer Orders
- B2C **order status tracking** with refresh, authoritative status header, last-updated marker and lifecycle timeline
- notifications, addresses and profile
- Wholesale/B2B storefront, dashboard, products, orders, invoices, invoice details, account statement, purchase reports and top products
- Arabic/RTL and English/LTR

The order tracking mock intentionally mirrors the current production behavior: status/timeline tracking is shown; a live delivery-vehicle map is not fabricated when the current Customer App does not provide it.

Artifact:
`FOODEX-Customer-UIUX-Mockup-APK`
