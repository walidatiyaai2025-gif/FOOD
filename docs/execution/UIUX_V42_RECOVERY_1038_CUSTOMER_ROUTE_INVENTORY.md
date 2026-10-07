# UIUX v4.2 Recovery — Customer Route & Dynamic-Surface Inventory (#1038)

Mission: **#1034 — UIUX-V42-RECOVERY**  
Owner: **#1038**  
Canonical route registry: `apps/customer_app/lib/core/routing/customer_routes.dart`

## Canonical production routes audited

### Platform / authentication
- `/entry` — unified Customer login/register.
- `/marketplace` — platform marketplace/store selector.
- `/diagnostics` — diagnostics/support surface.

### Retail / B2C
- `/home`, `/retail/:store/home`
- `/offers`
- `/products`, `/products/:id`, `/retail/:store/products/:product`
- `/categories`
- `/favorites`
- `/cart`
- `/auth/checkout`, `/checkout/address-payment`
- `/orders`, `/orders/:id/track`
- `/notifications`
- `/profile`, `/profile/addresses`, `/profile/settings`

### Wholesale / B2B
- `/b2b/home`
- `/b2b/dashboard`
- `/b2b/reports/purchases`
- `/b2b/products/top`
- `/b2b/products`, `/b2b/products/:id`
- `/b2b/cart`, `/b2b/checkout`
- `/b2b/orders`, `/b2b/orders/:id`
- `/b2b/invoices`, `/b2b/invoices/:id`
- `/b2b/account-statement`
- `/b2b/notifications`
- `/b2b/profile`, `/b2b/profile/addresses`

## Dynamic freshness audit

No canonical Customer business-data surface remains intentionally manual-refresh-only.

- Customer Orders: periodic polling for active orders, resume refresh, manual pull fallback, stale/offline banner + last confirmed update.
- Customer Account/Profile: resume refresh for profile/addresses/favorites/notifications.
- Favorites: resume refresh + manual pull fallback.
- Address book: resume refresh + manual pull fallback.
- Notification center: resume refresh + manual pull fallback.
- Retail Home/Catalog/Product surfaces: resume refresh; manual refresh where exposed.
- Retail Cart/Checkout: resume refresh; existing confirmed data remains visible where the screen owns a cached model.
- B2B Dashboard/remote states/Invoice detail: resume refresh.
- Wholesale Home/Catalog/Product Detail/Cart/Checkout/Orders/Order Detail: lifecycle observers refresh on app resume.
- Wholesale Catalog/Product Detail/Order Detail keep explicit cached/stale behavior where authoritative cached data exists.

Regression guard: `apps/customer_app/test/customer_v42_dynamic_refresh_contract_test.dart`.

## Identity/security audit

- Login visibly renders `Customer App / تطبيق العميل`.
- Remember Me and biometric unlock use secure persistence and do not expose a stored token before successful device authentication.
- Auth handoff preserves the exact safe pending destination and commerce context.

## Invoice audit

The B2B invoice detail is a real FOODEX invoice surface, not a generic receipt:
- configured/company branding path with FOODEX fallback;
- invoice identity/status/dates;
- seller and customer identity;
- authoritative item lines;
- subtotal/discount/delivery/tax/total;
- paid/outstanding/credit position;
- payments and ledger entries;
- invoice action/download path where allowed.

## Screenshot/runtime evidence

The official mobile screenshot test captures all representative Customer routes in **AR/RTL and EN/LTR** at 430x932. #1042 remains responsible for independent integrated review and any additional narrow/wide interaction evidence required by the central matrix.
