# V2 #2555 — P225 Commercial Pricing group authority

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, Catalog & Pricing, legacy P225 / native paragraph P214. Parent #2503. **Integration:** Foodex_V2 only; never main, production, tags, releases or cPanel.

## Placement: Reuse, not another group

The documented التسعير التجاري / Commercial Pricing heading describes a business function beneath Catalog & Pricing. P218/#2548 and P219/#2549 already establish the single canonical **catalog** group. T356R5/#2722 already moves the existing **commercial_sales_control** entry there. P226/#2556 provides the independently authorized Price Lists tab and B2B hub link; P227/#2557 uses Accounts → Customer Groups for segmented pricing. P225 must certify the functional journey, **not create a duplicate Sidebar group, route, permission or pricing engine**.

| Metadata | Canonical decision |
| --- | --- |
| Domain and entities | Catalog & Pricing, product commercial rules, b2b price tiers/rules, customer groups |
| Actor and goal | Authenticated Wholesale operator; discover scoped pricing work and reach the right authorized function |
| Frequency and density | Daily operations, existing Commercial Control workbench and Price Lists table |
| Placement rationale | REUSE existing Catalog group, Commercial Control and Price Lists; a new group/screen would duplicate P218/P219 |
| Navigation owner and type | AdminNavigation + AdminSidebarManager; Catalog SIDEBAR → Commercial Control → Price Lists PAGE_TAB; B2B DOMAIN_HUB secondary link |
| Canonical endpoints | admin.commercial.sales-control and admin.b2b.pricing-approvals |
| Permissions | catalog.view to open Commercial Control; independent b2b.pricing.view for lists, b2b.pricing.manage for mutations; DENY overrides |
| Store/channel and readiness | Actual B2B Wholesale principal and OperationalTenantScope; B2bPricingMode tier setting controls list readiness |
| Screen pattern | Existing accessible responsive admin shell, native keyboard links and horizontal tabs, no new markup |
| Localization | admin.nav_groups.catalog, admin.commercial_sales_control, commercial.tabs.price_lists in AR/RTL and EN/LTR |
| States | No empty/unauthorized group; disabled pricing mode hides Price Lists link and direct HTTP rejects the unready page |
| Back/fallback | Existing commercial tabs and Wholesale workspace navigation; no direct-URL-only business feature |

The sibling open leaves P228/#2558 quantity tiers, P229/#2559 profitability rules and P230/#2560 exception approvals remain separately owned. No claim of their completion is made.

## Source-specific executable tests

**New:** backend/tests/Feature/V2CommercialPricingGroupAuthorityTest.php

1. Real Sidebar registry contains one Commercial Control link in the existing Catalog group, no shadow group; bilingual AR/RTL and EN/LTR navigation opens a legitimate B2B HTTP Commercial Control and independently ready Price Lists view.
2. With price lists enabled, explicit DENY of b2b.pricing.view hides the Price Lists tab and forbids direct HTTP, without eliminating independently authorized Commercial Control. Explicit DENY of catalog.view removes that link and rejects direct HTTP.
3. Pricing mode OFF keeps separately authorized Commercial Control available, but hides the unready Price Lists tab and returns HTTP 404 on the readiness-gated list route.
4. A B2C store actor, a roleless actor and an inactive actor cannot inherit or access the Wholesale Commercial Control through Sidebar or forged URLs.

These checks use seeded real roles, permission overrides, HTTP controllers, real navigation projections, and B2B/B2C stores. They are not a replacement for existing P218/P219/P226/P227 acceptance, but bind them into the distinct P225 group journey and verify independent nested authorization.

## Surface/contract impact matrix

- **Backend:** new Laravel Feature regression only. No live permission, schema, API, pricing algorithm, transaction, migration, queue, cache or server behavior changed.
- **Dashboard:** existing real Catalog/Commercial/Price Lists surfaces under the same responsive shell and localized tabs. No new UI or route, no independent visibility security grant.
- **Customer Flutter:** not affected; no changed price-calculation or customer API contract, checkout, auth or notifications.
- **Driver Flutter:** not affected; B2C dispatch, collection, wallet, proof and notifications unchanged.
- **Van Flutter:** not affected; B2B inventory, visits, order routing, pricing APIs, offline replay and settlements unchanged.

Consequently no new mobile issue is justified by this isolated test/documentation change. Wholesale remains VAN ONLY; Retail remains DRIVER ONLY. No financial maker/checker or tenant/store isolation rule is modified.

## Verification and safe delivery

Focused test: run PHP Artisan test on tests/Feature/V2CommercialPricingGroupAuthorityTest.php inside backend. Apply Pint, PHPStan/security and AR/EN gates. Verify the actual exact-head **FOODEX V2 Isolation Gate**, **Repository Policy**, **Laravel Backend Tests**, **Localization Quality**, **Release Validation** and terminal **required-ci-gate**, together with any applicable MySQL/Redis fresh-install/update/recovery workflows, before squash merge.

Exclusive paths for this issue are the new test above and this new document. No shared AdminNavigation, sidebar registry, controller, Blade, translation, Flutter or release-path file was modified; open worker lanes retain their file ownership. One branch test/2555-v2-commercial-pricing-group-authority and one PR targeting Foodex_V2 only.

Manual browser/device/staging acceptance is deferred to V2 final owner review per #2503; never claim that UAT or deployment occurred. Close this leaf only when isolated integration and required automated verification are complete. Keep #2503 and unfinished sibling issues open.
