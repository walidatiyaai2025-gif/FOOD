# Customer VNext Screen Registry

Issue: #1324  
Parent: #1323  
Status: authoritative execution registry for Customer VNext routes. This file inventories the existing routes; it does **not** authorize replacement V2 screens.

## Global acceptance contract

Every customer route below must keep its current route authority, compose the shared Customer V3 tokens/components, support AR/RTL and EN/LTR, remain usable on small/standard/large phones, and provide deterministic loading/loaded/empty/error/offline-or-stale behavior where the route depends on remote data. Runtime visual evidence must come from the Customer screenshot harness and exact-head CI.

| Route | Current authority | VNext lane | Required evidence / states |
|---|---|---:|---|
| `/splash` | UnifiedCustomerAuthScreen | #1329 | AR/EN, small/large phone, transition |
| `/entry` | UnifiedCustomerAuthScreen | #1329 | login/register/error/session states, AR/EN |
| `/marketplace` | MultiStoreDesignScreen | #1325 | loaded/empty/error/offline, AR/EN |
| `/home` | RetailCustomerJourneyScreen | #1325 | approved Shopping Home, loaded/empty/error/offline |
| `/retail/:store/home` | RetailCustomerJourneyScreen | #1325 | approved Shopping Home, store-scoped |
| `/retail/:store/products/:product` | RetailCustomerJourneyScreen | #1326 | product loaded/missing/out-of-stock/error |
| `/offers` | RetailCustomerJourneyScreen | #1329 | loaded/empty/error/offline |
| `/products` | RetailCustomerJourneyScreen | #1326 | search/filter/list states, 3-column grid |
| `/products/:id` | RetailCustomerJourneyScreen | #1326 | gallery/price/unit/stock/add-to-cart states |
| `/categories` | RetailCustomerJourneyScreen | #1326 | loaded/empty/error/offline |
| `/favorites` | RetailCustomerJourneyScreen | #1329 | auth, loaded/empty/error/offline |
| `/orders` | RetailCustomerJourneyScreen | #1328 | loaded/empty/error/offline/history |
| `/notifications` | RetailCustomerJourneyScreen | #1329 | loaded/empty/error/offline |
| `/profile/addresses` | RetailCustomerJourneyScreen | #1329 | list/edit/location/validation/error |
| `/profile/settings` | RetailCustomerJourneyScreen | #1329 | language/logout/session states |
| `/diagnostics` | CustomerDiagnosticsScreen | #1324 | inventory-only internal route; no duplicate UI |
| `/cart` | RetailCustomerJourneyScreen | #1327 | empty/loaded/update/error/offline |
| `/auth/checkout` | UnifiedCustomerAuthScreen | #1327 | auth handoff/error/resume exact route |
| `/checkout/address-payment` | RetailCustomerJourneyScreen | #1327 | address/payment/review/validation/failure |
| `/orders/:id/track` | RetailCustomerJourneyScreen | #1328 | lifecycle/stale/error/deep-link |
| `/profile` | RetailCustomerJourneyScreen | #1329 | loaded/error/session/logout |
| `/b2b/home` | MultiStoreDesignScreen | #1325 | shopping-first home, AR/EN |
| `/b2b/dashboard` | B2bJourneyScreen | #1329 | loaded/error/offline |
| `/b2b/reports/purchases` | B2bJourneyScreen | #1329 | loaded/empty/error/offline |
| `/b2b/products/top` | B2bJourneyScreen | #1326 | loaded/empty/error/offline |
| `/b2b/products` | MultiStoreDesignScreen | #1326 | search/filter/list, compact responsive |
| `/b2b/products/:id` | MultiStoreDesignScreen when store-scoped; B2bJourneyScreen compatibility otherwise | #1326 | scoped + compatibility route |
| `/b2b/invoices` | B2bJourneyScreen | #1329 | loaded/empty/error/offline |
| `/b2b/invoices/:id` | B2bJourneyScreen | #1329 | loaded/error/deep-link |
| `/b2b/account-statement` | B2bJourneyScreen | #1329 | loaded/empty/error/offline |
| `/b2b/orders` | MultiStoreDesignScreen | #1328 | loaded/empty/error/offline/history |
| `/b2b/orders/:id` | MultiStoreDesignScreen | #1328 | detail/lifecycle/deep-link |
| `/b2b/notifications` | B2bJourneyScreen | #1329 | loaded/empty/error/offline |
| `/b2b/cart` | MultiStoreDesignScreen | #1327 | empty/loaded/update/error |
| `/b2b/checkout` | MultiStoreDesignScreen | #1327 | validation/failure/retry/success |
| `/b2b/profile/addresses` | B2bJourneyScreen | #1329 | list/edit/validation |
| `/b2b/profile` | B2bJourneyScreen | #1329 | loaded/error/logout/settings |

## Journey map

`/entry` -> shopping context -> `/home` or `/retail/:store/home` / `/b2b/home` -> catalog/category/search -> product detail -> cart -> checkout auth when required -> checkout -> success -> orders -> order detail/tracking -> account/settings.

The existing route authority remains canonical. A lane may redesign its current screen in place but may not add a sibling `V2`/replacement route for the same function.

## Evidence authority

- Route authority: `apps/customer_app/lib/core/routing/customer_route_authority.dart`
- Route definitions: `apps/customer_app/lib/core/routing/customer_routes.dart`
- Router: `apps/customer_app/lib/core/routing/customer_router.dart`
- Runtime screenshot harness: `apps/customer_app/test/screenshot_evidence_test.dart`
- Route/navigation tests: `apps/customer_app/test/customer_route_authority_test.dart`, `customer_navigation_test.dart`
- Shared design system: `apps/customer_app/lib/core/theme/customer_ui_v3_tokens.dart`, `foodex_theme.dart`, `apps/customer_app/lib/shared/customer_ui_v3/`, `customer_persistent_footer.dart`
