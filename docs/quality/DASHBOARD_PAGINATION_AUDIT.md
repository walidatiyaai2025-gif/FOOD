# FOODEX Dashboard Pagination Audit

Issue: #1085

This document is the repository audit for Dashboard record grids. The governing rule is that a multi-record operational grid must expose real server/data-source pagination and preserve the active query string. Tables that are intrinsically bounded detail or aggregate surfaces are explicitly classified as exceptions; they are not silently grandfathered.

## Record-list surfaces

| Surface / route | Grid | Source | Contract | Page size | Query/filter persistence |
|---|---|---|---|---:|---|
| Field Operations `/admin/field-operations/territories` | Geographic hierarchy | `geography_nodes` | Laravel server paginator `geography_page` | 25 | `withQueryString()` |
| Field Operations `/admin/field-operations/territories` | Service territories | `service_territories` | Laravel server paginator `territory_page` | 25 | `withQueryString()` |
| Catalog `/admin/catalog?tab=products` | Products | products/catalog/store joins | Laravel server paginator `product_page` | 25 | `withQueryString()` |
| Catalog `/admin/catalog?tab=categories` | Categories | categories/catalog/store joins | Laravel server paginator `category_page` | 25 | `withQueryString()` |
| Customer 360 `/admin/customer-360/{id}` | Order history | scoped customer orders | Laravel server paginator `orders_page` | 25 | `withQueryString()` |
| Customer 360 `/admin/customer-360/{id}` | Invoice history | scoped customer invoices | Laravel server paginator `invoices_page` | 25 | `withQueryString()` |
| Reports `/admin/reports` | Detail rows | ManagementReportService | offset-aware server pagination | 25 default; 25/50/100 accepted | paginator query preserves filters |
| System Update `/admin/settings/system-update` | Update history | `update_histories` | Laravel server paginator | 20 | `withQueryString()` |
| Flash Offers `/admin/b2c/commercial/flash-offers` | Current offers | `flash_offers` | Laravel server paginator `offer_page` | 25 | `withQueryString()` |
| Flash Offers `/admin/b2c/commercial/flash-offers` | Existing promotions | `promotions` | Laravel server paginator `promotion_page` | 25 | `withQueryString()` |
| B2B workspace module routes | Generic module rows | authoritative module record collection | server-side `LengthAwarePaginator`, `rows_page` | 25 | request query retained |
| B2B driver module | Assignment management | scoped assignment collection | server-side `LengthAwarePaginator`, `assignments_page` | 25 | request query retained |
| B2C workspace module routes | Generic module rows | authoritative module record collection | server-side `LengthAwarePaginator`, `rows_page` | 25 | request query retained |
| B2C driver module | Assignment management | scoped assignment collection | server-side `LengthAwarePaginator`, `assignments_page` | 25 | request query retained |
| Order Operations | Orders | scoped order query | existing paginator | existing page size | existing filters/query retained |
| Notifications | Notifications | scoped notification query | existing paginator | existing page size | existing filters/query retained |
| Notification Campaigns | Campaigns | scoped campaign query | existing paginator | existing page size | existing filters/query retained |
| Customer 360 index | Customers | platform customer query | existing paginator | existing page size | existing filters/query retained |
| Retail Stores | Stores | store query | existing paginator | existing page size | existing filters/query retained |
| Lookup Management | Lookups | scoped lookup query | existing paginator | existing page size | existing filters/query retained |
| System Lookups | Operational lookups | lookup query | existing paginator | existing page size | existing filters/query retained |
| System Inspector | Events | inspector event query | existing paginator | existing page size | existing filters/query retained |
| Security | Users/roles record grids | security queries | existing paginator | existing page size | existing filters/query retained |
| Translations | Translation records | translation query | existing paginator | existing page size | existing filters/query retained |
| Coupons | Coupon records | coupon query | existing paginator | existing page size | existing filters/query retained |
| Live Ads | Ad records | live-ad query | existing paginator | existing page size | existing filters/query retained |
| Field Operations Finance partial | finance operational rows | FieldOperationsFinanceService | existing custom server pagination | selectable 10/25/50/100 | query retained |
| Van Finance Support | van finance rows | finance service | existing custom server pagination | selectable 10/25/50/100 | query retained |

## Explicit bounded/detail exceptions

These are not open-ended record-list grids and therefore do not require pagination:

| Surface | Reason |
|---|---|
| App Versions | Fixed app/platform policy matrix (Customer/Driver/Van × Android/iOS). |
| Invoice detail | Line items belong to one invoice detail record. |
| Flash Offer Preview | Products belong to one offer preview/detail context. |
| Flash Offer Analytics | Tables are grouped aggregate summaries for one offer, not raw event-record grids. |
| B2B Dashboard “Latest orders” | Deliberately bounded dashboard snapshot with a normal “View all orders” route to the paginated Orders module. |
| Report breakdown tables | Bounded aggregate/status/category breakdowns; the main report record grid is paginated. |

## Anti-regression gate

`DashboardPaginationContractTest` scans Admin Blade tables and rejects a new unclassified table unless the file has a pagination contract or is a documented bounded/detail exception. It also locks the Geographic Engineering independent page keys and high-growth history paginators.

## Release gate

Implementation/merge is not completion for #1085. The issue remains open until a release containing these changes is actually published and runtime verification on that published version confirms Geographic Engineering pagination and representative Dashboard grids in Arabic/RTL and English/LTR. The closing comment must record the release version and source commit SHA.
