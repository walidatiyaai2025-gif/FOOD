# FOODEX Customer VNext — Execution and Launch Plan

Authority: GitHub umbrella #1323 and child Issues #1324–#1334.

Evidence ownership: [requirement matrix](FOODEX_CUSTOMER_VNEXT_REQUIREMENT_MATRIX.md) and [machine-readable requirements](FOODEX_CUSTOMER_VNEXT_REQUIREMENTS.json). Source identity: [real-data manifest](../testing/FOODEX_REAL_DATA_SOURCE_MANIFEST.md). Existing Customer routes/shared ownership: [Screen Registry](customer-vnext/SCREEN_REGISTRY.md) and [File Ownership](customer-vnext/FILE_OWNERSHIP.md).

## 1. Product objective
Transform the existing FOODEX Customer application into one coherent, production-ready shopping product without introducing parallel replacement screens. Existing routes, screens, business rules and APIs are reused/refactored wherever valid; presentation and user journey are redesigned in place.

The user must never feel a visual identity change between routes. Typography, header geometry, spacing, cards, controls, dialogs, bottom sheets, navigation, loading/empty/error states and RTL/LTR behavior are governed by one Customer Design System.

## 2. Owner-approved non-negotiables
- No HomeV2 / CartNew / OrdersNew style parallel screens.
- Successful Customer login lands on the existing Customer Home route redesigned as Shopping Home.
- Shopping-first journey; account/dashboard functions remain secondary.
- Home uses one dashboard-managed hero banner.
- Categories come from Dashboard/master data.
- No second banner below Categories.
- Best Sellers then All Products.
- Exactly 3 products per row.
- Product cards show no ratings.
- View More/pagination follows product sections.
- Dashboard owns content; Flutter owns presentation/dimensions/layout.
- All existing internal Customer screens inherit the same visual identity from first pixel to last pixel.

## 3. Customer screen registry
Before broad UI implementation, #1324 must enumerate every Customer route and all non-route surfaces:
- splash/auth/session-expired
- Home
- categories/listings
- search and all search states
- product details
- cart
- checkout/payment
- order success
- orders/order details/tracking
- account/profile
- addresses
- notifications
- offers/flash offers
- language/settings/logout
- dialogs, drawers, bottom sheets, popups
- loading/skeleton, empty, error, offline and permission states

Every registry entry records: route, current widget, entry points, exit/back behavior, API dependencies, business rules, new shared components, AR/EN status, responsive status, visual test status, owner Issue and completion evidence.

A route missing from the registry is a release defect.

## 4. Shared Customer Design System
#1324 owns shared Customer UI/navigation infrastructure. Other lanes consume it and do not create competing primitives.

Required token families:
- typography roles and line heights
- spacing scale and screen gutters
- radius/border/shadow
- colors and semantic states
- icon sizes and hit targets
- image aspect ratios
- header heights/variants
- bottom navigation geometry
- animation/motion durations
- responsive breakpoints/constraints

Required canonical components include:
- AppHeader / InnerHeader / SearchHeader
- CustomerBottomNavigation
- AppSearchBar
- ProductCard
- CategoryCard
- BannerCard
- Price
- QuantityStepper
- CartItem
- OrderCard
- StatusBadge / timeline
- AddressCard
- SectionHeader
- Primary/Secondary buttons
- inputs/selectors
- EmptyState / ErrorState / OfflineState
- Skeleton primitives
- AppDialog / AppBottomSheet

Screen code must not invent arbitrary font sizes, padding, radii, colors or headers when a shared token/component exists.

## 5. Approved Shopping Home contract
#1325 redesigns the existing Home route in place.

Order:
1. unified Customer header
2. search
3. one hero banner from Dashboard
4. categories from Dashboard/master data
5. Best Sellers
6. All Products
7. View More/pagination
8. canonical bottom navigation

Rules:
- three product cards per row
- no product ratings
- no promotional banner below Categories
- mobile portrait is the primary layout
- Arabic RTL and English LTR are first-class
- banner/category/product content may change dynamically without changing geometry
- long names, missing images, out-of-stock products, loading/offline/error states cannot break the approved grid

## 6. Existing internal screens
#1326–#1329 redesign existing routes in place.

### Catalog / Search / Product
Reuse canonical ProductCard and image/price/availability components. Search must cover idle, typing, suggestions, results, filtered results, no results, slow/error/offline. Product Details must support real package/selling-unit information and large clear imagery.

### Cart / Checkout
Canonical cart rows, quantity controls, totals, discount/delivery, address/delivery/payment/review hierarchy, validation/retry/payment failure and Order Success.

### Orders / Tracking
Canonical order cards, status badges and timeline semantics with deterministic back/deep-link behavior.

### Account and secondary routes
Account/Profile, Addresses, Notifications, Offers/Flash Offers, language/customer settings/logout/session-expired and every residual route discovered by the registry.

No route may retain a legacy header, typography scale, card system or overlay styling.

## 7. Real-data testing baseline
#1330 converts the owner-supplied current database into a repository-safe deterministic test baseline.

The raw database dump is NOT committed because the repository is public and the dump contains PII/contact data plus authentication/token-bearing tables.

The repository fixture must:
- preserve realistic relational structure and business states
- use deterministic test identities/contact data
- remove/replace production PII, passwords, tokens, push tokens and provider secrets
- preserve realistic catalog/store/selling-unit/order/order-item/payment/inventory relationships
- be reproducible by documented setup/reset commands
- be usable by all engineers and automated E2E tests

Observed source baseline is recorded in docs/testing/FOODEX_REAL_DATA_SOURCE_MANIFEST.md. The verified Oct-12 source contains 566 translation rows; the old 230-row intake count is superseded. Missing/ambiguous source price/carton values remain explicit data-review blockers, never invented values.

#1330 owns one top-level `Database\Seeders\FoodexLaunchSeeder` orchestrator, exposing deterministic `preflight(): array` with `ready` and `fingerprint`, plus re-runnable transactional Laravel Seeder invocation. Catalog-specific seed components remain behind this contract. #1331 and #1332 consume it without copying catalog data or deriving a competing baseline.

## 8. Official FOODEX products and image quality
The owner-supplied product document is the launch catalog source for product name, package size, carton quantity, price where supplied, and source imagery.

Seed/catalog work must map the document into authoritative backend entities: category, brand, product, selling unit, price, store assignment, stock/availability defaults and images.

The embedded document images are source references, not final storefront-quality guarantees. Current ordinary JPEG/PNG assets are roughly 311–422 px on their long side. The final product must use approved high-resolution originals where available or approved high-resolution derivatives.

Automated/QA checks must fail the launch catalog when required images are missing, obviously thumbnail-sized, broken, stretched or mapped to the wrong product.

## 9. Protected reset / reseed
#1331 adds a visible System Settings/Admin operation for a controlled FOODEX fresh start.

Required behavior:
- privileged permission check
- destructive impact summary
- strong confirmation
- audit log
- documented reset policy
- clear demo/test transactional data according to policy
- preserve security/system configuration that must survive
- reseed official FOODEX catalog, pricing, selling units, images, store mappings, categories and approved banner baseline
- refresh derived caches/search/indexes
- idempotent in non-production
- production safeguards including backup/preflight and secondary confirmation

No engineer/tester should need manual SQL to return the system to the agreed FOODEX starting state.

## 10. Production preflight and owner Launch
#1332 implements an owner-controlled Dashboard Launch gate.

Mandatory preflight covers:
- schema/migrations
- required environment/configuration
- official FOODEX seed completeness
- image completeness/quality
- Customer API contract health
- Screen Registry closure
- AR/EN and RTL/LTR checks
- complete E2E purchase/order/account journey
- payment/delivery/notification readiness
- no demo/test PII or transactions in launch state
- application/release artifact requirements
- backup/rollback evidence
- exact-head required CI green

Launch remains blocked while mandatory checks are red. The Launch action requires privilege, explicit confirmation and creates an auditable launch/version event.

## 11. Visual and functional quality gates
Each screen must cover:
- normal
- loading/skeleton
- empty
- error
- offline
- slow API
- long Arabic
- long English
- missing image
- out of stock/disabled action where relevant
- representative small, standard and large phone widths
- back/deep-link/navigation behavior

Primary routes must have approved screenshot/golden coverage so typography/header/grid drift is detected by CI.

## 12. Parallel work map
- #1324: shared Customer tokens/components/navigation + Screen Registry; owns shared Customer UI files.
- #1330: database/test fixture/product/image pipeline; can run immediately in parallel with #1324.
- #1331: Dashboard reset/reseed; can build reset infrastructure in parallel and consume #1330 seed contract.
- #1325–#1329: route-specific redesign lanes. They may audit and prepare isolated work immediately but must not duplicate shared files owned by #1324.
- #1332: preflight/Launch infrastructure can start in parallel; final closure waits for upstream evidence.
- #1333: independent final certification and closes last.
- #1334: documentation-only plan/intake manifest.

If two lanes need the same shared file, one owns it, the second waits/rebases. One Issue = one owner = one branch = one PR according to AGENTS.md.

## 13. Definition of Done
The Customer VNext effort is complete only when:
- every existing Customer route is registered and redesigned or explicitly retired by approved scope
- no mixed old/new identity remains
- realistic sanitized FOODEX data provisions cleanly
- official FOODEX products/prices/package data and approved images are available
- reset/reseed returns a dirty tester environment to a deterministic start
- full browse -> search/category -> product -> cart -> checkout -> order -> tracking -> account flows pass in AR/EN
- visual regression proves header/type/card/grid consistency
- all required tests and exact-head CI are green
- production preflight is green
- the owner can press Dashboard Launch with auditable evidence and no known launch blocker

Final certification is #1333.

## 14. Requirement evidence and convergence

Each stable CVN requirement has exactly one owning implementation or independent QA Issue in the synchronized matrix. Owners attach exact-source evidence for source, automated behavior and applicable runtime/locale/responsive checks. Shared foundation completion does not certify every downstream screen; closed Issues, green generic CI, builds and generated screenshots alone do not prove product compliance.

#1333 re-reads the authoritative plan, matrix, integrated source, test results and reviewed runtime evidence. Any OPEN/PARTIAL/FAIL/UNKNOWN/UNOWNED requirement blocks certification. After the implementation source is frozen, release artifacts must identify that source/version lineage. A later business/application change invalidates affected evidence and requires dependent gates to rerun. Completing documentation #1334 does not close #1323 or authorize Launch.
