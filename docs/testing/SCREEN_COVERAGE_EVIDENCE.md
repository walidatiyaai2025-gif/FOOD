# RC-03 — 46-Screen Working-Product Evidence

Audit issue: #123  
Baseline: `c09910cfec643333e7ca721090fe0e54d659c2fb` (after #116 premium cross-surface acceptance)  
Audit date: 2026-09-26

## Evidence rules

This audit distinguishes **route existence**, **runtime behavior**, and **visual parity**.

- **Verified** means the current product has a reachable implementation with a real user interaction/mutation or a screen that renders authoritative content appropriate to the row.
- **Partial** means a route/shell or API call exists but the approved screen is not usable as implemented (for example, a generic "loaded" state or generic Admin count shell).
- **Gap** means the approved flow/route is absent or a required authoritative runtime behavior is not wired.
- AR/EN is based on current locale/direction support and tests. It does not upgrade a partial functional screen to verified.
- Exact per-row visual parity is **unverified** because the archive declared by `docs/design-reference/INDEX.md` — `Foodex_Design_Reference_Laravel_Flutter (1).zip`, SHA-256 `bfc481ead664c3b683fb84be45fabc3108394154b9326d27ef3c58cee5171f45` — is not checked into this repository and was not available from the accessible file library during this audit.
- Row 37 is the exception: the premium B2C Dashboard has a checked-in PH-05 design contract/reference plus deterministic seed and #116 acceptance.

Summary at this baseline:
- **6/46 functionally verified** rows.
- **28/46 partial** rows.
- **12/46 gaps**.
- **1/46** has independent PH-05 visual acceptance; exact original per-row visual parity for the other 45 remains unverified.

## Row-by-row matrix

| # | Surface / approved route | Current implementation path | Scope | AR/EN | Functional evidence | Visual evidence | Implementation commit / remediation |
|---:|---|---|---|---|---|---|---|
| 1 | B2B Customer `/b2b/login` | `CustomerAppRouter` → `B2bJourneyScreen` → `CustomerLoginAction` | Public login; B2B channel established after auth | AR RTL + EN LTR supported | **Verified**: real `POST /api/v1/auth/login`, no public B2B registration | Source ZIP unavailable | `36248b2` (#66) |
| 2 | B2B Customer `/b2b/dashboard` | `B2bJourneyScreen` + `HttpB2bApi` | Authenticated B2B | AR/EN | **Partial**: GET runs, successful payload collapses to generic “loaded” card | Unverified | `36248b2`; fix #151 |
| 3 | B2B Customer `/b2b/reports/purchases` | same remote-state path | Authenticated B2B | AR/EN | **Partial**: endpoint called, report payload not rendered | Unverified | `36248b2`; fix #151 |
| 4 | B2B Customer `/b2b/products/top` | `B2bJourneyScreen._endpoint` | Authenticated B2B | AR/EN | **Gap**: maps to generic products endpoint; no authoritative ranking; payload also not rendered | Unverified | #142 + #151 |
| 5 | B2B Customer `/b2b/invoices` | `B2bJourneyScreen` + `GET /api/v1/b2b/invoices` | Authenticated B2B | AR/EN | **Partial**: invoices payload not rendered | Unverified | `36248b2`; #151 |
| 6 | B2B Customer `/b2b/account-statement` | `B2bJourneyScreen` + statement API | Authenticated B2B | AR/EN | **Partial**: statement payload not rendered | Unverified | `36248b2`; #151 |
| 7 | B2B Customer `/b2b/orders` | `B2bJourneyScreen` + orders API | Authenticated B2B | AR/EN | **Partial**: list payload not rendered | Unverified | `36248b2`; #151 |
| 8 | B2B Customer `/b2b/orders/:id` | `B2bJourneyScreen` + order detail API | Authenticated B2B | AR/EN | **Partial**: detail payload not rendered | Unverified | `36248b2`; #151 |
| 9 | B2B Customer `/b2b/invoices/:id` | `B2bJourneyScreen` + invoice detail API | Authenticated B2B | AR/EN | **Partial**: detail payload not rendered | Unverified | `36248b2`; #151 |
| 10 | B2B Customer `/b2b/products` | `B2bJourneyScreen` + products API | Authenticated B2B | AR/EN | **Partial**: search shell + generic remote state; product rows not rendered | Unverified | `36248b2`; #151 |
| 11 | B2B Customer `/b2b/products/:id` | local detail shell + `AddCartAction` | Authenticated B2B | AR/EN | **Gap**: `_endpoint()` returns null, so authoritative B2B product/pricing/MOQ detail is never loaded | Unverified | #143 |
| 12 | B2B Customer `/b2b/cart` | remote cart GET + local checkout action | Authenticated B2B | AR/EN | **Partial**: cart payload is not rendered | Unverified | `36248b2`; #151 |
| 13 | B2B Customer `/b2b/profile` | profile GET through remote state | Authenticated B2B | AR/EN | **Partial**: profile payload is not rendered | Unverified | `36248b2`; #151 |
| 14 | B2C Customer `/splash` | `B2cJourneyScreen` | Guest | AR/EN | **Verified** bootstrap/splash state | Unverified | `e30057c` (#65) |
| 15 | B2C Customer `/entry` | `B2cJourneyScreen` guest/login actions | Guest | AR/EN | **Verified** guest path is available without auth; login path routes to protected checkout auth | Unverified | `e30057c` |
| 16 | B2C Customer `/stores` | static `B2cJourneyScreen` empty card | Guest | AR/EN | **Gap**: backend stores API exists but app does not call/render it | Unverified | #140 |
| 17 | B2C Customer `/home` | static sections | Guest/B2C | AR/EN | **Gap**: no selected-store/category/product runtime read | Unverified | #140 |
| 18 | B2C Customer `/offers` | static empty state | Guest/B2C | AR/EN | **Gap**: offers API exists but is not wired | Unverified | #140 |
| 19 | B2C Customer `/products` | search bar + static empty state | Guest/B2C | AR/EN | **Gap**: product list/filter API exists but is not wired | Unverified | #140 |
| 20 | B2C Customer `/products/:id` | `AddCartAction` only | Guest browsing; mutation requires auth | AR/EN | **Gap**: product detail GET is not rendered; store context is not established by the read journey | Unverified | #140 |
| 21 | B2C Customer `/cart` | static empty card + checkout button | B2C | AR/EN | **Gap**: `GET /api/v1/cart` exists but cart contents are not loaded/rendered | Unverified | #141 |
| 22 | B2C Customer `/auth/checkout` | `CustomerLoginAction` | Guest → B2C auth | AR/EN | **Verified** credential validation + real login mutation + protected-route handoff | Unverified | `e30057c` + current action API |
| 23 | B2C Customer `/checkout/address-payment` | `CheckoutAction` | Authenticated B2C | AR/EN | **Verified mutation path**: address/payment inputs, idempotency key, real checkout POST, order route handoff | Unverified | current `customer_action_widgets.dart` |
| 24 | B2C Customer `/orders/:id/track` | static received/preparing/on-way cards | Authenticated B2C | AR/EN | **Gap**: order detail/status GET exists but tracking never loads it | Unverified | #141 |
| 25 | B2C Customer `/profile` | static addresses/favorites/orders sections | Authenticated B2C | AR/EN | **Gap**: profile/addresses/favorites APIs exist but are not wired | Unverified | #141 |
| 26 | B2B Super Admin `/admin/b2b/login` | no Web route/controller/view | Unauthenticated management user | N/A until implemented | **Gap**: admin middleware returns 401 when no session; no browser login entry exists | Unverified | #139 |
| 27 | B2B Admin `/admin/b2b/dashboard` | `B2bWorkspaceController` + generic `b2b-workspace.blade.php` | SUPER_ADMIN/B2B_ADMIN | AR/EN | **Partial**: real counts, but generic shell rather than approved management dashboard | Unverified | `b8cc80b` (#64); #144 |
| 28 | B2B Admin `/admin/b2b/stores` | same generic workspace | B2B Admin | AR/EN | **Partial**: no working store/branch management list/actions | Unverified | #144 |
| 29 | B2B Admin `/admin/b2b/clients` | same generic workspace | B2B Admin | AR/EN | **Partial**: no working B2B client management surface | Unverified | #144 |
| 30 | B2B Admin `/admin/b2b/products` | same generic workspace | B2B Admin | AR/EN | **Partial**: no working catalog/inventory management surface | Unverified | #144 |
| 31 | B2B Admin `/admin/b2b/orders` | generic workspace | B2B Admin | AR/EN | **Partial**: no operational order management UI | Unverified | #145 |
| 32 | B2B Admin `/admin/b2b/drivers` | generic workspace | B2B Admin | AR/EN | **Partial**: no driver/delivery management UI | Unverified | #145 |
| 33 | B2B Admin approved `/admin/b2b/pricing-approvals` | current module is `/admin/b2b/pricing` generic shell | B2B Admin | AR/EN | **Partial + route mismatch** | Unverified | #145 |
| 34 | B2B Admin `/admin/b2b/reports` | generic workspace | B2B Admin | AR/EN | **Partial**: reporting services exist elsewhere, approved screen not integrated | Unverified | #146 |
| 35 | B2B Admin approved `/admin/b2b/settings-permissions` | current module is `/admin/b2b/settings` generic shell | B2B Admin | AR/EN | **Partial + route mismatch**; Security Center exists separately | Unverified | #146 |
| 36 | B2C Admin `/admin/b2c/login` | no Web route/controller/view | Unauthenticated management user | N/A until implemented | **Gap**: no browser login entry | Unverified | #139 |
| 37 | B2C Admin `/admin/b2c/dashboard` | `B2cWorkspaceController` + `B2cDashboardService` + premium view | assigned B2C store / SUPER_ADMIN | AR RTL + EN LTR | **Verified**: server KPIs, distribution, low stock, recent orders, permissions/store scope, deterministic seed | **Verified against PH-05 reference contract**, responsive breakpoints tested | `4770669` (#113), `cc15b1a` (#114), `c09910c` (#116) |
| 38 | B2C Admin `/admin/b2c/products` | generic non-dashboard B2C module shell | assigned B2C store | AR/EN | **Partial**: no working product management list/actions | Unverified | `8d8b2db` (#63); #147 |
| 39 | B2C Admin `/admin/b2c/inventory` | generic shell | assigned B2C store | AR/EN | **Partial**: no inventory management UI | Unverified | #147 |
| 40 | B2C Admin `/admin/b2c/orders` | generic shell | assigned B2C store | AR/EN | **Partial**: no operational order UI | Unverified | #147 |
| 41 | B2C Admin `/admin/b2c/customers` | generic shell | assigned B2C store | AR/EN | **Partial**: no customer management UI | Unverified | #147 |
| 42 | B2C Admin `/admin/b2c/promotions` | generic shell | assigned B2C store | AR/EN | **Partial**: no promotions/discount management UI | Unverified | #148 |
| 43 | B2C Admin `/admin/b2c/drivers` | generic shell | assigned B2C store | AR/EN | **Partial**: no driver/delivery management UI | Unverified | #148 |
| 44 | B2C Admin approved `/admin/b2c/storefront-preview` | current module is `/admin/b2c/storefront` generic shell | assigned B2C store | AR/EN | **Partial + route mismatch**; no actual storefront preview | Unverified | #148 |
| 45 | B2C Admin `/admin/b2c/content` | generic shell | assigned B2C store | AR/EN | **Partial**: no content/banner management UI | Unverified | #148 |
| 46 | B2C Admin `/admin/b2c/reports` | generic shell; global reports center exists separately | assigned B2C store | AR/EN | **Partial**: approved B2C reports/analytics/store-settings surface not integrated | Unverified | #149 |

## Defects created by this audit

- #139 — Management Web login entry for B2B/B2C admins.
- #140 — B2C Customer store/catalog read journey.
- #141 — B2C Customer cart/tracking/profile read journey.
- #142 — authoritative B2B top-products ranking.
- #143 — authoritative B2B product detail.
- #144 — B2B Admin core dashboard/store/client/catalog screens.
- #145 — B2B Admin orders/drivers/pricing screens.
- #146 — B2B Admin reports/settings-permissions surfaces.
- #147 — B2C Admin products/inventory/orders/customers screens.
- #148 — B2C Admin promotions/drivers/storefront/content screens.
- #149 — B2C Admin reports/store-settings surface.
- #151 — render authoritative B2B Customer payload data across remote journey screens.

## Release consequence

The former PC issue closures prove substantial backend/domain and route work, but they do **not** prove all 46 approved product screens are working. Release closure (#124/#125) must not be presented as a fully usable-product completion while these P1 screen/runtime defects remain open. Release-artifact work may continue independently, but product-completeness reporting must keep these defects visible.
