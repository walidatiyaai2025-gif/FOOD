# V2 #2556 / P226 — قوائم الأسعار / Wholesale price lists

Source: FOODEX Unified RBAC/ERP/Sidebar Discussion, 2026-11-09, P226 (Catalog & Pricing). Parent #2503. Integration: **Foodex_V2 only**. Manual browser/staging/device UAT: **DEFERRED_TO_V2_FINAL** under the owner's 2026-11-10 decision.

## Product and navigation authority

| Dimension | Resolution |
|---|---|
| Domain | Catalog & Pricing; B2B wholesale commercial policy |
| Entity | Existing b2b_price_tiers and b2b_price_rules; canonical principal Wholesale store with store-owned products and catalog |
| User goal | Authorized commercial operator opens real price lists, reviews tier/product prices and minimum quantities, and saves permitted price rules |
| Role | B2B actor with catalog.view to see Sales Control plus independent b2b.pricing.view for price lists. b2b.pricing.manage is required to save. B2B Dashboard provides a second entry for pricing viewers independently of catalog access |
| Workflow, frequency, density | Operational price-list review and occasional rule changes; existing pagination and table/form in wholesale B2B workspace |
| Existing parent | Catalog → commercial_sales_control (admin.commercial.sales-control); Wholesale B2B dashboard (admin.b2b.module, dashboard) |
| Placement type | REUSE existing price lists renderer via Catalog PAGE_TAB / B2B DOMAIN_HUB context link, not New Screen and not an independently invented sidebar child |
| Canonical route | admin.b2b.pricing-approvals, already mapped to B2bWorkspaceController::show with module=pricing |
| Renderer | admin.b2b-workspace (pricing module), existing tier/product Lookup selectors and prices table |
| Fallback/back path | Commercial Sales Control retains its own back-to-wholesale-workspace tab; wholesale workspace retains standard global header/Sidebar. When pricing tiers are disabled, the price-lists entry is intentionally hidden, and direct route is rejected by the existing 404 readiness guard |
| Permission authority | Display link only when real pricing.view grant, effective OperationalTenantScope B2B principal-store scope, and B2bPricingMode::tiersEnabled(principal store). Direct route still checks b2b.pricing.view and tenantContext->wholesale. Form POST checks b2b.pricing.manage and pricing mode; explicit per-user DENY remains authoritative |
| Channel and store | Wholesale principal only. Never pass a forged retail store ID to the destination. Read-side pricing rows join b2b catalog, correct store ID and non-quarantine catalog; write rejects foreign product with 422 |
| Readiness/entitlement | Exact existing b2b.pricing_tiers_enabled setting. Default OFF means no price-list link or active price list page; no independent feature flag, entitlement bypass or invented readiness override |
| AR/EN, RTL/LTR | New commercial.tabs.price_lists label in Arabic and English; responsive native anchors in existing focusable tabs/quick links, min-height 44px tab touch target. Same locale, permissions and real destination across desktop, tablet and mobile web |
| Data sources | Actual b2b_price_rules, b2b_price_tiers, products, catalogs, store_products and persisted settings; no fake/fallback price data |

## Implementation detail

- Extend CommercialDashboardController::salesControl to project one nullable pricingListsUrl only after independent price-list permission, effective B2B store scope and mode check; the URL uses the existing route and does not accept a client store id.
- Commercial Dashboard displays a localized Price lists tab when the URL is present. Flash Offer pages do not inherit the link as a false authorization grant.
- Wholesale dashboard displays a separate authorized quick link only when its existing visibleModules calculation includes pricing (uses the same b2b.pricing.view permission and enabled-tier condition as the canonical route). View-only actors may inspect lists but never gain write permission.
- No new route, new page, schema, migration, account/customer eligibility logic, API endpoint or alternative price engine. Existing B2B PricingController and B2bWorkspaceController continue to own the actual rule save; this implementation only solves discoverability.
- The current existing form uses authoritative tier/product dropdowns (Lookup), existing quantity minimum and currency formatting and server-side audit; no raw internal ID fields added for business input.
- Shared AdminNavigation.php is **untouched** because other active V2 workers currently own it. The Catalog link already lands on the existing Sales Control; price lists are now one discoverable page-level click away and independently surfaced on the B2B dashboard.

## Automated acceptance and risk controls

New test file backend/tests/Feature/V2WholesalePriceListsSidebarHttpTest.php covers six HTTP scenarios:

1. Real Catalog entry point plus price list tab and B2B dashboard quick link across Arabic RTL and English LTR, actual price list table with a real persisted product and tier, correct URL and renderer.
2. Existing authorized HTML form POST persists B2B price rule (with minimum quantity and unit price) and UI re-read confirms server state.
3. Disabled pricing tiers hide all entry points; direct pricing route is 404 (module unready) and forged save returns 409 without mutation.
4. An explicit b2b.pricing.view DENY hides links and returns 403 on direct list; independent b2b.pricing.manage DENY allows viewing but blocks POST and the form.
5. Retail product and rule are excluded from Wholesale price lists despite a forged query store_id, and submitted foreign product is rejected with no same-store rule.
6. Roleless, inactive and Retail-only users cannot acquire wholesale price-list access from Sidebar or direct URLs.

This is functional HTTP acceptance evidence, not a screenshot-based claim of manual browser verification. No screenshots required by current #2472 contract. Existing exact-head Required CI handles UI structural policy, backend PHP/Pint/static analysis/security, localization, MySQL/Redis install/upgrade/recovery and regression tests.

## Customer, Driver and Van effects

- **Dashboard affected:** two existing, reusable navigation surfaces gain independently permissioned/ready Price lists links; existing page, form and data source unchanged. No hidden UI authorization considered a security boundary.
- **Backend affected:** CommercialDashboardController read-side projection only. Existing server-side pricing API, store/customer pricing computation, auth, DB mutations, and contract unchanged.
- **Customer Flutter not affected:** no changed REST API payload, price tier/customer assignment, checkout calculation, discount, account eligibility, or product-price behavior. Customer sees the same existing backend-authoritative price rules.
- **Driver Flutter not affected:** no B2C Driver dispatch, proof, payments or lifecycle change.
- **Van Flutter not affected:** no B2B Van fulfillment, offers, visits, quantities or lifecycle change. B2B VAN ONLY and B2C DRIVER ONLY unchanged.
- No new mobile issue is warranted because no app-facing API, UX or business logic contract changes.

## Delivery gates

One Issue #2556, branch feat/2556-v2-wholesale-price-lists-entry, one PR targeting Foodex_V2. **Never** merge without Repository Policy, V2 Isolation, full Laravel Backend Tests, Fast-Fail PHP/Pint/PHPStan/Composer security/OpenAPI, Arabic/English Localization Quality, real MySQL/Redis fresh-install/upgrade/recovery and terminal required-ci-gate all success at exact latest head. Fix any failures on the same PR. Record tested head SHA, CI run links, clean mergeability, merge SHA and integrated test evidence before closing the leaf. Parent #2503 remains OPEN for deferred manual UAT and formal signed Setup/Update and synchronized APK release gates. No merge into main, tag, release or production deployment.
