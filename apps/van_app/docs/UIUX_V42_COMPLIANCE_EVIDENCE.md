# FOODEX Van v4.2 compliance evidence — Issue #1040

Canonical implementation branch: `feat/1040-van-v42-full-sweep`  
Recovery integration target: `release/1034-uiux-v42-recovery`  
Frozen visual baseline: `prototype/van-uiux-mockup@59335d21879736d075404c8cb1483dc9f0f09686`

## Frozen 19-screen inventory

| # | Surface | Production implementation | Authoritative source |
|---|---|---|---|
| 1 | Login | `features/auth/van_login_screen.dart` | Van auth API + secure session store |
| 2 | Home Dashboard | `features/foundation/van_dashboard_page.dart` | wallet + assigned customers |
| 3 | Routes | `features/visits/van_routes_page.dart` | actor-scoped Van visits / route metadata |
| 4 | Route Map | `features/visits/van_route_map_page.dart` | visit route key + customer-address coordinates |
| 5 | Route Detail | `features/visits/van_route_detail_page.dart` | actor-scoped Van visits |
| 6 | Customers | `features/foundation/van_customers_page.dart` | `/api/v1/van/customers` |
| 7 | Visit Workspace | `features/visits/van_visit_workspace_page.dart` | visit lifecycle + Van orders + configured no-order reasons |
| 8 | Customer 360 | `features/foundation/van_customer_360_page.dart` | customer scope + collection context |
| 9 | Product Catalog | `features/orders/van_product_catalog_page.dart` | customer/store-scoped Van catalog |
| 10 | Order Builder | `features/orders/van_order_builder_page.dart` | shared order draft + backend quote |
| 11 | Order Review | `features/orders/van_order_review_page.dart` | backend quote/options + idempotent submit |
| 12 | Orders | `features/orders/van_orders_page.dart` | actor/customer-scoped Van order feed |
| 13 | Offers | `features/commercial/van_offers_page.dart` | canonical Commercial Policy / Flash Offer APIs |
| 14 | Wallet | `features/wallet/van_wallet_page.dart` | custody ledger |
| 15 | Collection | `features/wallet/van_collection_page.dart` | collection context + idempotent collect |
| 16 | Receipt | `features/wallet/van_receipts_page.dart` | custody ledger receipts |
| 17 | Remittance | `features/wallet/van_remittance_page.dart` | custody balance + idempotent remit |
| 18 | Notifications | `features/notifications/van_notifications_page.dart` | canonical notification feed/read state |
| 19 | Profile & Settings | `features/foundation/van_profile_page.dart` | authenticated Van session + secure logout |

`VanFoundationScreen._body()` has an explicit enum case for all 19 screen IDs. There is no generic production fallback for non-login surfaces.

## v4.2 requirement evidence

### MV01 — compact title/header + full-width data-first
- Shared FOODEX Van shell uses the approved FOODEX green identity and compact app bar.
- Operational screens use full-width lists/cards with the current business state first.
- Representative visual capture is generated at 430×932 and compact 360×800.

### MV02 — compact rows / business labels / no raw-ID entry
- Catalog, orders, routes, visits, finance and notifications use one-line business labels with ellipsis overflow.
- Order completion selects an order by business order number, not by internal ID entry.
- No-order completion selects configured localized reason labels.
- Customer and route selectors use names/codes from authoritative lookups.

### MV03 — foreground refresh + truthful stale/offline
Dynamic Dashboard, Routes, Route Map, Route Detail, Customers, Customer 360, Visit Workspace, Orders, Wallet/Collection/Receipt/Remittance and Notifications refresh on resume or expose a fresh server operation before mutation. Where retained data can be shown after a refresh failure, it is explicitly labelled as last-confirmed/stale.

### AV01 — visible Van identity
The secure login surface visibly identifies the Van app in AR/EN.

### AV02 — Remember Me + biometrics
Remember Me, secure persisted sessions and biometric re-authentication remain owned by the secure authentication persistence flow. Tokens are never displayed on Profile & Settings.

### AV03 — capability parity / explicit exceptions
Implemented app-level capabilities include auth/session persistence, push-session binding, notifications/read state, customer scope, route/visit lifecycle, commercial offers, authoritative catalog/quote/order submission, custody collection, receipt/remittance and profile/logout.

Explicit boundary: no local/offline business decision is fabricated. Route Map plots only coordinates that exist on authoritative customer addresses; stops without coordinates are omitted from the map with a visible explanation. Order completion is unavailable until a same-customer/store authoritative order exists. No-order completion is unavailable until a configured active reason exists.

## Backend authority added for this lane

- Existing `/api/v1/van/visits` now exposes route context and authoritative customer-address coordinates.
- New Van-scoped catalog/order endpoints reuse the existing `AdminOrderManagementService`, `CommerceQuoteService`, commercial policy reservation and inventory reservation paths.
- Van order creation uses `source=van`, commercial channel `van`, and a required idempotency key.
- Existing Dashboard order calls remain backward-compatible through default service arguments.
- Notifications reuse the existing canonical Notification API.

## Automated evidence

### Functional / contract
- `apps/van_app/test/app_test.dart`
  - 19-screen inventory lock
  - navigation shell
  - Customer 360
  - Collection / Receipt / Remittance
  - Dashboard
  - Routes / Route Map / Route Detail
  - Visit lifecycle start and completion with authoritative order lookup
  - Notifications/read state
  - complete Catalog → Builder → Review → Submit → Orders flow
  - Profile/session scope
- `backend/tests/Feature/VanVisitControllerTest.php`
- `backend/tests/Feature/VanOrderControllerTest.php`

### Visual / responsive
- `apps/van_app/test/screenshot_evidence_test.dart`
- Standard AR/RTL + EN/LTR evidence: `ScreenShots/03_Van/` at 430×932.
- Compact representative AR/RTL + EN/LTR evidence: `ScreenShots/03_Van_Compact/` at 360×800.
- `.github/workflows/mobile-screenshot-capture.yml` executes and uploads these files as CI artifacts.

## Closure boundary

Issue #1040 implementation can be considered code-complete only after exact-head Required CI, Repository Policy and Van screenshot evidence are green. Per the issue acceptance contract, final issue closure additionally requires the integrated recovery build to pass independent runtime visual gate #1042 against the frozen prototype target.
