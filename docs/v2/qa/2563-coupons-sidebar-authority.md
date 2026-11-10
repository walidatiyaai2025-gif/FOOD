# V2 P233 / #2563 — coupon navigation and scoped authorization evidence

Integration lane: `Foodex_V2` only. Source trace: actual DOCX paragraph P222, Sidebar index P233, parent #2503.

## Adaptive placement and route authority
- Business domain: Catalog & Pricing / Promotions. Reuse the already functioning Coupon Management page; no new page, separate ledger or Sidebar security policy.
- Entity: `MarketingCoupon`; roles: Super Admin, authorized Wholesale B2B Admin, store-scoped Retail operators.
- Goal: search/read, create, update, activate/deactivate, and delete coupons within authorized wholesale or retail scopes.
- Pattern: existing coupon administration / Data Explorer with create/edit actions; desktop/tablet/mobile web reuse the same responsive page; Arabic RTL and English LTR reuse `coupons.*` translations.
- Canonical route: `admin.coupons.index` (`GET /admin/coupons`), management actions `admin.coupons.store/update/toggle/destroy`.
- Navigation: existing Sidebar `coupons` item, canonical Dashboard context `coupons`, no direct-URL-only function. Existing group is Advertising; a final migration to the target Catalog/Promotions hierarchy is owned by the V2 Sidebar IA/layout integration and cannot be performed in parallel with active `AdminNavigation.php` PR owners.
- Authorizer: `CouponManagementController` with server-side `OperationalTenantScope` and `TenantContextResolver`. Menu is only a projection, never an authorization grant.
- Fallback: return to the previous authorized Dashboard domain; no new direct navigation or unknown-route fallback.
- Data scope: `MarketingCoupon.scope_key` and `store_id`; B2B and B2C cannot leak into each other.
- Fields: Store is a managed lookup, channel/discount type are enums, coupon code is an operator-entered promotional code, localized title/description are bilingual free text, schedule/limits are constrained business inputs.

## Affected-surface classification
- Backend: affected (the existing management HTTP authorization and tenant-scoped mutations now honor explicit store/global DENYs and manage-versus-view split).
- Dashboard: the existing coupons page now hides create/edit/toggle/delete controls for view-only actors and only offers manageable stores and records; backend authorization remains independent.
- Dashboard / mobile web: affected by the canonical UI's permission-aware action visibility; no new route or translation keys. Must validate responsive/accessibility at final V2 gate.
- Customer Flutter: not affected by these Dashboard-only HTML/POST routes. Customer quote/checkout coupon APIs and response schemas remain unchanged.
- Driver Flutter: not affected; no delivery routes/API/schema/permissions changed.
- Van Flutter: not affected; no Van routing/price/dispatch API/schema changed.

## Automated coverage
- `php artisan test --filter=V2CouponsSidebarAuthorizationTest`
- `php artisan test --filter=AdvertisingCouponsAndMobileTrialTest`
- `vendor/bin/pint --test app/Http/Controllers/Admin/CouponManagementController.php tests/Feature/V2CouponsSidebarAuthorizationTest.php`
- Required exact-head V2 isolation, repository policy, localization, security and backend CI must be green before merge.

Test goals: visible authorized coupon route, bilingual RTL/LTR direction, scoped list visibility, explicit global/store DENY, denied cross-store create/toggle, read-only UI hiding write actions while server also returns 403, feature-off denial and empty navigation group.

## Outstanding integration acceptance
The shared Sidebar generator `backend/app/Support/AdminNavigation.php` is currently modified by independent V2 PRs #3063, #3064, and #3068. Do **not** edit it concurrently. After those PRs settle, verify `coupons` disappears for a user denied in every permitted store and that the P233 target Catalog/Promotions hierarchy/three-level depth remains correct. The Issue remains OPEN until these checks and exact-head CI pass. Manual real-device/browser acceptance is deferred under #2503 to the final V2 gate; this does not waive automated requirements.
