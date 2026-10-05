# Changelog

## 1.0.56 - 2026-10-05

- Publish the completed Catalog / Customer 360 / B2B Orders redesign and the #924 CI stabilization wave now merged through PR #925.
- Ship compact single-row Catalog product management with contextual actions, store-configured currency display, and preserved catalog/pricing/inventory business logic.
- Ship tabbed Customer 360 with Finance first, editable audited Wholesale credit limit, and map-based address location selection that stores coordinates automatically without exposing manual latitude/longitude inputs.
- Ship the B2B Orders create-order modal, improved Arabic workflow/payment labels, and cleaner order operations while preserving authoritative quote, stock, pricing, finance, permissions and routing.
- Include the associated reliability hardening for System Inspector, CSRF refresh, Customer/Driver retries and backoff, invoice PDF authorization/rendering, preview unavailable states, live polling cooldowns, and B2B finance query performance from the green #924 integration branch.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.56 / mobile build 1.0.56+56 without changing production minimum-version, force-update, Driver location-enforcement or Assistant activation settings.

## 1.0.55 - 2026-10-05

- Promote the owner-approved Customer visual and functional convergence from #920 / PR #921: FOODEX reference login, single-screen Business Dashboard, compact Top Products, discoverable Wholesale Business Dashboard shortcut, redesigned More surface, and refreshed Orders/footer composition.
- Keep all business values, permissions, account/store entitlement, finance metrics, orders and navigation authoritative to the existing backend/API/session state with no hardcoded production business data.
- Preserve Arabic/English RTL/LTR behavior, Remember Me and biometric sign-in, exact Wholesale store context, Retail/Wholesale isolation, and the validated five-destination Customer navigation contract.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.55 / mobile build 1.0.55+55 without changing production minimum-version, force-update, Driver location-enforcement or Assistant activation settings.


## 1.0.54 - 2026-10-05

- Publish the final accepted C13 Customer journey after umbrella #858 and integrated gate #873 / PR #917, including the canonical 13-screen Arabic/English visual and operational convergence.
- Complete discoverable principal Wholesale recovery from Customer Dashboard/Shopping so an authenticated Wholesale customer can return from Retail context without logout, while preserving authoritative store/account isolation.
- Preserve authoritative Customer finance, purchasing power, checkout approval, invoice/order/driver collection contracts and centralized sanitized System Inspector reporting across Dashboard, Customer and Driver.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.54 / mobile build 1.0.54+54 without changing production minimum-version, force-update, Driver location-enforcement or Assistant activation settings.
- 1.0.53 was not promoted to authoritative main; 1.0.54 is the immutable final C13 distribution.

## 1.0.52 - 2026-10-04

- Publish the completed FOODEX operational-completion wave #828 after the final integrated #841 gate: gated Add Store and New Order popup wizards, central stable-code operational lookups, and the admin login default to the B2B dashboard.
- Ship the cumulative Catalog ZIP sample/preview/import flow for Products, Categories, Brands and images, plus server-authoritative OUT_OF_STOCK enforcement across API, Dashboard and Customer purchasing surfaces.
- Converge order lifecycle/status tabs and preserve Delivered history while removing completed work from active Driver queues; add complete Driver pickup/delivery details, Failed Delivery / تعذر التوصيل, and lifecycle-aware live Home card refresh.
- Publish Customer launch notification-campaign popups with authoritative eligibility/frequency/store-channel scoping, and Finance invoice From/To/customer filters with matching PDF and Excel exports.
- Publish current authoritative Customer and Driver App Review/Preview parity with visible freshness/stale/disconnected state and no fake live-data fallback.
- Preserve B2B/B2C/store isolation, AR/EN + RTL/LTR behavior and the green final integrated release gate; synchronize Dashboard, Customer and Driver release identities at 1.0.52 / mobile build 1.0.52+52 without changing production force-update or minimum-version policy.

## 1.0.51 - 2026-10-03

- Publish the approved Customer B2B visual convergence from #823 / PR #824: separate Home discovery from the full Products catalog, lock Wholesale surfaces to the FOODEx green identity, use readable two-column phone cards, surface Brand identity, and remove per-card cart shortcuts while preserving product-detail purchasing.
- Replace the raw Business Account profile payload with clear account, company/contact, linked-store, linked-wholesale-account, customer-profile, address and favorites summaries without changing Dashboard or backend authorization contracts.
- Improve Driver delivery execution: show the complete authoritative delivery address in Assignment Details and open Google Maps using the immutable navigation coordinates when present, with the saved address as the external-map fallback when coordinates are unavailable.
- Replace the Driver Home accepted-order pickup shortcut with the prominent Delivery Failed / تعذر التوصيل action; keep Picked Up / تم الاستلام available inside Assignment Details so the authoritative lifecycle remains intact.
- Unify Customer and Driver launcher artwork under the approved FOODEx Economical Group identity and make Driver local/FCM notification presentation resolve to the same launcher identity.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.51 / mobile build 1.0.51+51; keep production minimum-version, force-update, Driver fresh-location enforcement and Assistant activation settings unchanged.

## 1.0.50 - 2026-10-03

- Replace the fragile Wholesale marketplace product modal with a full product-details route that preserves exact store context and exposes product imagery, description, customer price, MOQ/order increment, stock information and add-to-cart behavior.
- Make Favorites functional across Wholesale and Retail product cards/details with authenticated customer-domain handling and preserved store/channel provenance, while keeping Retail detail behavior intact.
- Render four product cards per row on normal phone widths for both Wholesale and Retail, with a safe narrow-width fallback and regression coverage for overflow/preview parity.
- Repair Dashboard Catalog Management after the production /admin/catalog undefined-variable failure, keep product image/primary-image editing operational, and expose a direct authorized Catalog Management sidebar entry including SUPER_ADMIN.
- Add regression coverage for B2B product-detail routing, favorites, catalog rendering and mobile grid policy; Backend validation, Customer Flutter/iOS validation, MySQL/Redis acceptance, UI Visual QA and mobile screenshot gates are green for #817 / PR #818.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.50 / mobile build 1.0.50+50; keep production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.49 - 2026-10-03

- Prevent signed-in Retail owners/managers from reopening their own Retail storefront through stale remembered commerce context, the Marketplace store shortcut, the persistent footer, or direct Retail catalog routes; invalid own-store context now falls back to the allowed platform/Wholesale context while backend self-store purchase blocking remains authoritative.
- Make Dashboard media authoritative across the Customer catalog: Wholesale categories expose and render their category image, Product payloads retain primary product imagery, and Retail/Wholesale product contracts expose Brand names and Brand images with safe icon fallback when media is missing or fails.
- Render real Wholesale category and Brand rails from catalog data instead of fixed placeholder icons, and surface Brand imagery on Retail product cards without weakening store/channel isolation.
- Add immediate Dashboard image previews before save for Product multi-image uploads, Category images, and Brand images while preserving the existing saved-image thumbnails and validation rules.
- Add regression coverage for stale own-store header/footer navigation and category/Brand image API contracts, with Backend/MySQL, Customer Android/iOS, UI Visual QA and update-package validation green in #812 / PR #813.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.49 / mobile build 1.0.49+49; keep production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.48 - 2026-10-03

- Promote the owner-approved Customer APK baseline from #794 and preserve the final Home, persistent footer, store-switching and Dashboard-aware image behavior across the synchronized release.
- Complete the corrective Customer Commerce V4 closure: canonical Retail merchant platform identity and linked Wholesale account reconciliation (#797), authoritative own-store exclusion and backend blocking (#798), isolated Wholesale/Retail My Orders tabs with immutable store/channel provenance (#799), and coherent Dashboard primary-owner binding/reassignment (#800).
- Preserve one platform Customer login across Wholesale and Retail while keeping cart, checkout, order history, operational queues, notifications and driver routing authoritative to the originating store and channel.
- Include the final integrated corrective gate #775 / PR #805, with Backend/MySQL, Customer Android/iOS, Driver Android/iOS, runtime screenshot evidence, App Preview parity and legacy-route guards green against the final integrated main.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.48 / mobile build 1.0.48+48; retain production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.47 - 2026-10-02

- Publish all post-1.0.46 Driver corrections tracked through #761/#763: secure Remember Me, optional biometric sign-in, delivery Today/All/From-To filters, and the unified accepted-delivery Receive/Failed execution path for both B2B and B2C.
- Publish Customer Commerce V4 (#764, lanes #765-#773): one platform Customer identity across Retail and Wholesale, Dashboard-authoritative Retail banners with 5-second direct exact-store entry, store-scoped carts/order routing, unified login/register with Remember Me/biometric resume, complete Retail and Wholesale journeys, converged Dashboard order intake, and App Preview parity.
- Keep Retail/Wholesale as commerce context rather than separate authentication personas; preserve exact store/channel authorization, tenant isolation, self-store purchase protection, pricing/MOQ authority and driver/notification routing across the synchronized release.
- Ship the corrected Dashboard Customer App Preview runtime/inspector so authenticated preview uses the unified platform Customer identity while exposing auth mode separately from exact commerce context, with Draft/Published isolation and shared Flutter runtime parity.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.47 / mobile build 1.0.47+47 because published 1.0.46 is immutable; preserve production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.46 - 2026-10-02

- Publish the completed unified Wholesale/Retail commerce-isolation program (#734): authoritative platform identity and Retail Store ownership, backend self-store purchase protection, platform-first Customer entry with exact Retail placement/store routing, and strict B2B/B2C order ownership.
- Ship context-safe Customer address books and Wholesale order history/timeline, with Retail/Wholesale address, order and checkout data isolated by authoritative channel/store identity.
- Enforce Wholesale/Retail Driver tenant isolation and converge the full Driver App shell and Assignment Details lifecycle actions while preserving proof, failure and status-transition requirements.
- Isolate operational notification audiences and deep links by customer/driver/store/channel context, and auto-launch Dashboard App Preview into published Customer guest or the first eligible Driver context.
- Include final Customer + Driver E2E convergence and release guards; skip reserved 1.0.45 and synchronize Dashboard, Customer and Driver release identities at 1.0.46 / mobile build 1.0.46+46 without changing production force-update, minimum-version, Driver location-enforcement or Assistant activation settings.

## 1.0.44 - 2026-10-01

- Publish the completed Customer Journey V2 (#675) as NEW-only production runtime: store-scoped Retail catalog/cart/checkout, guest-to-auth cart continuity, account surfaces, authoritative orders/tracking, operational notifications, integrated guest E2E acceptance, legacy B2C purge and anti-regression guard.
- Publish the completed Driver Journey V2 (#686) as NEW-only production runtime: authoritative active-assignment flow, start/delivered/failed completion sheets, notes/proof handling, Dashboard delivery evidence, lifecycle notifications, assignment-to-proof E2E acceptance, legacy Driver journey purge and anti-regression guard.
- Keep Customer and Driver commerce/delivery context authoritative across navigation, deep links, notifications and release preview gates, with the converged shared APP-PREVIEW and Platform Customer Commerce CI coverage.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.44 / mobile build 1.0.44+44 without changing production force-update, minimum-version, Driver location-enforcement or Assistant activation settings.

## 1.0.43 - 2026-10-01

- Republish the Dashboard update under a new immutable version so installed 1.0.42 systems receive the shared-host public-runtime repair from #670/#671.
- Ship canonical `backend/public/**` files and safe shared-host aliases for `/assets/**`, `/brand/**`, `/demo/**`, and `/preview/**` in the same Dashboard update package.
- Repair public static-file and directory permissions during update extraction so Leaflet, Driver live tracking, FOODEX branding, and Customer/Driver App Preview runtimes resolve from the production document root.
- Preserve production AppVersion minimum/force-update rows, Driver fresh-location enforcement state, and Assistant disabled/read-only defaults.

## 1.0.42 - 2026-10-01

- Publish the complete MOBILE-PROD-UX package: production static asset reliability, authoritative Driver delivery lifecycle and notifications, Driver Home/Deliveries redesign, persistent Customer sessions, Dashboard-managed retail banners, and the redesigned Customer marketplace home.
- Include the final integrated mobile acceptance lane with backend, Customer/Driver Flutter and iOS validation, runtime screenshot evidence, and APP-PREVIEW visual/pixel-parity gates.
- Ship the self-contained Customer and Driver Flutter Web preview runtimes inside the Dashboard update package so App Preview updates do not require a separate runtime deployment path.
- Integrate FOOD Assistant V1 as a deterministic Arabic/English conversational copilot with authoritative FOODEX business/operations data, no LLM or external AI API, disabled by default and read-only by default.
- Preserve production AppVersion minimum/force-update rows and Driver fresh-location enforcement activation state; this release does not activate those policies.

## 1.0.41 - 2026-10-01

- Publish all reliability fixes merged after the already-distributed 1.0.40 under a new immutable release identity; 1.0.40 is not reused.
- Ship production distribution support for the real Customer and Driver Flutter Web preview runtimes with versioned artifacts and deployment validation.
- Add Driver version-policy failure observability with safe timeout/network/HTTP/parse/policy classifications, retry context and correlation IDs.
- Make protected Customer B2B authentication return channel-aware while preserving exact safe internal destination/query semantics.
- Harden Customer runtime/product error UX with retry/back recovery, safe support references and sanitized diagnostics instead of raw internal route text.
- Harden Dashboard live Driver map initialization/feed failure handling, empty-feed timestamps and local Leaflet asset completeness.
- Unify Mobile Settings with authoritative AppVersion/location-rollout readiness and surface exact safe blockers without changing production enforcement.
- Preserve existing production AppVersion minimums, force-update state and Driver fresh-location enforcement activation state.

## 1.0.40 - 2026-10-01

- Synchronize Dashboard, Customer and Driver release identities at 1.0.40 / mobile build 1.0.40+40.
- Fix the Dashboard Coupons page HTTP 500 when optional coupon start/end timestamps are null.
- Safely redirect malformed/non-numeric Customer 360 placeholder references to the Customer 360 index while preserving the valid numeric scoped route.
- Add regression coverage for both System Inspector production incidents.
- Preserve production Driver minimum-version and fresh-location enforcement settings unchanged.


## 1.0.39 - 2026-09-30

- Synchronize Dashboard, Customer and Driver release identities at 1.0.39 / mobile build 1.0.39+39 after the completed Driver tracking rollout chain.
- Ship the real Customer Flutter Web preview runtime and authenticated/guest read bridge, including Draft/Published revision resolution, scoped live invalidation, parity coverage and sanitized preview diagnostics.
- Ship the revisioned storefront Draft/Published backend foundation and preview configuration contracts required by the real application preview runtime; editor activation remains governed by its separate task.
- Add Dashboard live Driver tracking, including the dedicated page, sidebar access and compact B2B/B2C dashboard map cards using locally served map assets.
- Add Driver minimum-version policy enforcement in the app runtime while preserving the existing production minimum-version setting; this release does not mutate production AppVersion rows.
- Add server-side fresh Driver location enforcement capability with audited enable/disable controls and a stable recovery contract, while keeping enforcement OFF unless activated separately through the governed production path.
- Close the unaudited environment-variable enable bypass so absence of the persisted environment-scoped enforcement setting resolves OFF.
- Add active-delivery Driver background location lifecycle for Android/iOS, with foreground-service/native declarations, logout and permission revocation shutdown, and no broad Android background-location permission.
- Add the real Driver Flutter Web preview bridge and dedicated preview Web validation without enabling native/background tracking in preview.
- Preserve rollout separation: publishing 1.0.39 does not activate Driver location enforcement or change the production minimum-supported version; heartbeat evidence and production activation remain separate operational gates.


## 1.0.38 - 2026-09-30

- Complete the dashboard-only recovery path for installations that received 1.0.36 files while database migrations remained pending: install the migration-free 1.0.37 bootstrap first, then the cumulative 1.0.38 update so migration auto-detection triggers backup + pending migrations without a manual checkbox or shell command.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.38 / mobile build 1.0.38+38.
- Fix production invoice PDF downloads when an existing Dashboard installation does not have the newly declared TCPDF Composer dependency in its vendor directory.
- Ship a pinned self-contained TCPDF 6.11.3 runtime and DejaVu font assets inside the Dashboard Update package, outside the protected Composer vendor tree.
- Prefer normal Composer autoload when TCPDF is installed and fall back to the bundled runtime automatically, requiring no SSH, Composer command or manual server-side action.

## 1.0.37 - 2026-09-30

- Recover dashboard installations where the 1.0.36 migration flag was omitted by shipping a new cumulative migration-bearing update.
- Auto-detect database migration files inside uploaded dashboard update ZIP packages so future updates do not depend on a manual checkbox.
- Remove the manual migration checkbox from the updated System Update interface while retaining backward-compatible request handling.

## 1.0.36 - Platform customer addresses, delivery location and lifecycle notifications

- Unify each Platform Customer into one cross-channel address book shared by Wholesale and Retail journeys, with manual entry, explicit foreground location sharing, corrected map pins, default-address management and strict ownership isolation.
- Validate checkout address ownership on the backend and persist an immutable delivery-address snapshot on the order so later address-book edits cannot rewrite historical delivery data.
- Add full Customer 360 address CRUD plus exact delivery-address and map actions in Order Operations without leaking coordinates into unrelated list views, logs or public APIs.
- Carry the immutable delivery coordinates into Driver assignments and expose a safe one-tap navigation action while preserving B2B/B2C assignment boundaries.
- Make order/assignment lifecycle notifications backend-authoritative for Customer and Driver, with safe deep-link payloads, durable delivery logs, deduplication, retry/backoff and invalid-token revocation.
- Harden mobile push lifecycle with explicit logout revocation, foreground/local-notification tap routing, cold-launch recovery and a stable per-installation identity that reconciles FCM token refresh without revoking another device.
- Add OpenAPI contracts, cross-surface PCX-09 E2E acceptance, Arabic/English mobile screenshot evidence and Admin runtime visual evidence for the address/location journey.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.36.

## 1.0.35 - Order operations detail stability

- Fix HTTP 500 when opening Order Management details for orders with driver assignment history.
- Cast driver assignment `assigned_at` and `completed_at` timestamps to datetimes before timezone formatting.
- Add regression coverage for an order detail containing completed driver assignment timestamps.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.35.

## 1.0.34 - Engagement, live advertising, operations and platform branding

- Add image-capable notification testing and promotional campaign pushes with FOODEX brand fallback imagery.
- Register Customer App push devices before login and keep anonymous installs eligible after logout.
- Surface foreground and data-only/silent Customer pushes as visible local notifications.
- Add store-scoped Live Ads administration with scheduling, duration, frequency, CTA, image upload and Customer App popup rendering.
- Add Operations > Order Management with platform/store-safe filtering, exact status and driver visibility, driver reminder pushes, status changes and driver assignment actions.
- Extend Retail and Wholesale storefront composition with configurable departments alongside categories, brands and existing managed banner/section layouts.
- Apply the FOODEX Economical Group logo to dashboard, Customer App and Driver App branding surfaces.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.34.

## 1.0.33 - Explicit platform customer marketplace identity

- Integrate the remaining #402 public platform marketplace work on top of the newer 1.0.32 wholesale-first Customer experience.
- Add an explicit `platform_customers` identity table while preserving the existing platform-user compatibility flag.
- Backfill platform identities for existing 1.0.32 registered customers during upgrade.
- Add public platform storefront and product endpoints with configurable principal Wholesale store resolution.
- Materialize B2B/B2C customer projections from one platform identity and keep store/channel order routing isolated.
- Allow registered platform customers to fall back to safe base Wholesale pricing when no explicit tier rule exists.
- Keep Dashboard, Customer and Driver release identities synchronized at 1.0.33.

## 1.0.32 - Wholesale-first Customer marketplace and platform registration

- Open the Customer app into the platform main Wholesale storefront after splash without requiring sign-in.
- Show Retail stores as a compact horizontal banner strip capped at roughly 20% of the viewport; tapping a banner opens that Retail storefront.
- Add public guest-safe marketplace and Wholesale storefront browsing APIs.
- Add Customer self-registration with one platform account and an active STANDARD Wholesale account.
- Materialize Retail customer records lazily per selected Retail store while preserving tenant isolation.
- Route carts and orders by the actual selected store/channel, not by a separate app registration.
- Keep checkout, order history and profile actions authenticated while allowing guest browsing.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.32.

## 1.0.31 - Open-ended B2B sales dashboard range

- Rename the wholesale dashboard chart from Daily sales / المبيعات اليومية to Sales / المبيعات.
- Remove the 31-day B2B dashboard date-range ceiling.
- Make From and To optional: no dates shows all available wholesale sales, From-only runs through today, To-only includes all available sales up to that date, and both dates apply the exact range.
- Keep the query restricted to the authoritative B2B principal scope and add regression coverage.
- Keep Dashboard, Customer and Driver release identities synchronized at 1.0.31.

## 1.0.30 - Retail store accordion and lookup creation UX

- Show existing Retail stores as a single-open accordion ordered from newest to oldest, with the latest-created store expanded by default.
- Keep store editing, Wholesale tier, support inspection and manager/role controls inside each accordion panel.
- Replace the generic inline Lookup create block with a clear type-specific Add button and responsive modal dialog.
- Use "Add new brand / إضافة علامة تجارية جديدة" for Brands and "Add new unit / إضافة وحدة قياس جديدة" for Units.
- Preserve tenant scope, explicit support access, image validation, RTL/LTR behavior and existing lookup authorization.
- Keep Dashboard, Customer and Driver release identities synchronized at 1.0.30.

## 1.0.29 - Advertising center, coupons and mobile trial login

- Add the Advertising / الدعايا admin section with promotional campaigns and tenant-scoped coupon management.
- Add complete Wholesale vs Retail coupon isolation, redemption rules, audit history and checkout application.
- Add per-retail-store feature switches for advertising campaigns and coupons.
- Add temporary username-only mobile trial login for Customer and Driver apps behind the dedicated feature flag, plus logout.
- Show synchronized 1.0.29 version identity in Dashboard, Customer and Driver so the main trial-distribution workflow can publish the deployable changes.

## 1.0.28 - Version-locked dashboard APK downloads and admin account/header cleanup

- Add a dedicated Applications section to the admin sidebar with direct Customer and Driver Android APK download links.
- Resolve each APK from the installed FOODEX dashboard version so version X can only download assets published under release tag vX.
- Publish Customer APK, Driver APK and BUILD_INFO.json as versioned GitHub Release assets from the trial distribution workflow.
- Synchronize Dashboard, Customer app and Driver app version identity at 1.0.28.
- Include the Firebase HTTP v1 notification object-serialization fix already merged after 1.0.27.
- Move account settings, language switching and sign-out out of the sidebar footer into the avatar menu beside live notifications.

## 1.0.27 - Professional store selector and Driver active-assignment hardening

- Ship the professional Customer multi-store selector v3 with the exact جملة / التجزئة tabs, live store-selector API data, responsive RTL/LTR behavior, backend logos/artwork fallbacks, and explicit loading/error/empty/closed-store states.
- Keep Retail and Wholesale selection context isolated and route the selected store/receiving-store context into the existing tenant-safe mobile flows.
- Make the Driver app request `scope=active` explicitly instead of loading assignment history by default.
- Treat `reassigned` assignment rows as historical in both the Driver API active scope and the Driver UI active filter, alongside delivered, failed, cancelled and unassigned rows.
- Add backend, HTTP integration and widget regression coverage for active-assignment visibility.
- Preserve the Firebase service-account OAuth2 push flow and full dispatcher assign/unassign/reassign controls already present on main.


## 1.0.26 - Dynamic Wholesale storefront and synchronized mobile builds

- Add a dedicated B2B/Wholesale Storefront Builder to the Wholesale admin workspace.
- Manage Wholesale logo, branding, theme colors, hero content, home-section order/visibility and uploaded banners from the dashboard.
- Reuse the canonical storefront settings/sections/media model instead of creating a separate hard-coded Wholesale UI engine.
- Add an authenticated Wholesale storefront API protected by existing B2B entitlement and Retail-to-Wholesale account mapping.
- Make the customer mobile Wholesale Home load its branding, colors, hero and section composition dynamically from the backend.
- Keep B2B pricing, MOQ, stock and checkout server-authoritative.
- Publish synchronized Customer and Driver Android builds as 1.0.26.


## 1.0.25 - Storefront administration and dashboard reporting controls

- Add the Retail Storefront Design Builder for store-scoped branding, theme colors, logo upload, home-section ordering/visibility, service zones, storefront banners and live administration preview.
- Keep Storefront administration tenant-safe: Retail managers can mutate only their assigned store, while platform support requires explicit audited support context.
- Standardize dashboard filter/search actions on the canonical FOODEX green action style across administration.
- Replace the fixed/single-day dashboard period controls in B2B and B2C with explicit From/To date ranges, defaulting to the last 7 days and supporting up to 31 days.
- Make B2B/B2C KPIs, revenue/order charts, order distribution and recent orders honor the selected range; B2B top products now follow the same range.
- Compare KPI deltas against the immediately preceding period of equal length and adapt chart spacing/labels for wider ranges.
- Preserve RTL/LTR, responsive behavior, Retail store/support context and dashboard search context.


## 1.0.24 - Driver assignment production hardening

- Reconcile B2B and Retail driver execution permissions during production upgrades so existing driver roles can execute their assigned deliveries after an update.
- Treat cancelled driver assignments as historical rather than active work in both the Driver API and Driver app filters.
- Block transitions against cancelled or unassigned assignment rows while preserving assignment history.
- Keep the Firebase service-account authentication and complete assign/unassign/reassign controls introduced in 1.0.23.
- Add regression coverage for cancelled assignment visibility and production permission reconciliation.

## 1.0.23 - Firebase service-account push and complete driver order control

- Replace expiring manually-entered Firebase access tokens with encrypted Google/Firebase service-account JSON and automatic OAuth2 token generation/cache for Firebase HTTP v1, while retaining legacy access-token compatibility.
- Add a Firebase connection test in Mobile & Push Settings and expose the sanitized provider/OAuth failure reason in test sends and delivery logs.
- Show the current driver and assignment status directly in B2B and B2C order management.
- Allow dispatchers to assign, pull/unassign, and reassign orders between drivers while preserving prior assignment rows and audit history.
- Add a dedicated Drivers & Delivery assignment-management panel covering current and historical assignments.
- Reconcile legacy Driver/store and assignment/store ownership from the authoritative order so already-assigned orders become visible in the Driver app again.
- Treat pulled/unassigned deliveries as historical instead of active Driver tasks, with Arabic/English status copy and regression coverage.

## 1.0.22 - Driver reliability, clear admin errors, and password reset

- Admin mutation conflicts such as duplicate driver assignment, missing Wholesale pricing, and invalid business state now return to the same screen and appear in the FOODEX feedback popup instead of the generic 409 error page.
- Driver App launches its UI before Firebase/push initialization so messaging startup cannot block the application opening.
- Driver login now uses the FOODEX branded surface, Arabic/English layout, and a password visibility eye control; native Android builds continue to apply the approved FOODEX icon and splash assets.
- Drivers & Delivery now supports authorized B2B and B2C driver password resets with tenant/store enforcement, password confirmation, audit logging, and immediate revocation of existing driver API sessions.
- Driver mobile package version aligned to 1.0.22 and covered by Flutter/backend regression tests.


## 1.0.21 - Wholesale Principal & Retail Commercial Setup
- Make FOODEX itself the single Wholesale principal; remove user-managed Wholesale branch selectors and keep warehouses beneath the principal.
- Create and edit B2B orders from a selected source warehouse and reserve stock only from that warehouse.
- Make B2B drivers, catalog, pricing and settings inherit the main Wholesale principal without selecting a store.
- Require a logo and Wholesale price tier when provisioning a Retail store; synchronize that tier to the Retail store's linked B2B purchasing account.

## 1.0.20 - System Inspector Production Hotfix
- Automatically restore and verify `public/storage` during Dashboard Update execution so uploaded media remains publicly reachable after upgrades.
- Prevent invalid Retail-linked Wholesale account status mutations in the B2B UI and explain that the Retail store controls the lifecycle.
- Exclude active B2B accounts without an approved price tier from order creation, preventing the pricing 403 recorded by System Inspector.
- Keep the shared dashboard order form on Egyptian pound display and retain all 1.0.19 Retail production fixes.

## 1.0.19 - Retail Administration Production Fixes
- Fix direct Retail catalog access for platform support context and add a canonical category-management route so product/category administration no longer falls into the Wholesale tenant path.
- Complete Retail inventory management with warehouse creation, initial/current stock balance creation and stock adjustments from the Retail workspace.
- Add Retail customer image upload, replacement and removal with a neutral fallback avatar when no image is available.
- Replace free-form banner URLs with store-scoped product/category targets and accept valid banner images without the previous minimum-dimension rejection.
- Render Arabic PDF reports with Unicode RTL fonts instead of WinAnsi text to eliminate garbled Arabic exports.
- Reconcile production schema drift for category images plus customer-image and banner-target fields, with regression coverage for the reported production defects.

## 1.0.18 - Premium B2B Dashboard Reference Layout
- Rebuild the Wholesale dashboard to match the supplied premium reference proportions with four KPI cards, a seven-day sales chart, order distribution donut, latest orders, top-selling products, and operational alerts.
- Use live tenant-scoped B2B orders, customers, inventory, invoices and product sales instead of mock numbers.
- Add responsive RTL/LTR behavior while preserving the widescreen administration shell and B2B authorization boundaries.
- Add regression coverage for the reference 841x564 dashboard geometry and live business data.

## 1.0.17 - Widescreen Responsive Administration
- Make the shared FOODEX administration shell fluid on 1440p, 1920p and ultrawide displays instead of capping content at narrow fixed widths.
- Expand the Retail dashboard across the available viewport with wider desktop grids, larger analytics surfaces and consistent spacing.
- Standardize the desktop/sidebar/tablet geometry across Reports, Mobile Settings, Catalog and Lookup administration.
- Keep data tables inside self-scrolling containers so narrow screens do not force page-level horizontal scrolling.
- Align responsive behavior around common 1023px tablet, 767px mobile and 479px compact breakpoints while preserving RTL/LTR sidebar behavior and business logic.

## 1.0.16 - B2B Upgrade RBAC Reconciliation
- Reconcile canonical built-in FOODEX roles and permission assignments during upgrades so existing installations cannot retain stale B2B authorization state.
- Restore B2B_ADMIN and wholesale operational roles to their canonical global scope and expected permissions, including Orders, Catalog, Inventory, Drivers, Pricing, Finance, Reports and Settings access.
- Preserve custom/delegated roles unchanged and keep Retail store isolation and cross-channel restrictions intact.
- Add an upgrade regression test that starts from an intentionally stale B2B_ADMIN database and verifies every approved B2B administration module opens successfully.

## 1.0.15 - Admin Authorization Surface Audit
- Replace raw 403 responses on visible Retail store-scoped lookup actions with an actionable Explicit Support Access validation flow for platform owners.
- Keep Retail tenant isolation intact: SUPER_ADMIN must still explicitly enter audited support access before mutating store-owned Brands or Units.
- Add automated navigation authorization coverage for SUPER_ADMIN, B2B_ADMIN and B2C_STORE_ADMIN so every visible admin destination must open without 403/404/5xx responses.
- Preserve forbidden cross-domain and cross-store surfaces while making the visible administration experience internally consistent.

## 1.0.14 - Business Navigation, Self-Service Profile and Premium Mobile Commerce
- Reorder administration navigation around the actual business flow and make the sidebar compact/collapsed by default with a single authoritative permission-driven implementation.
- Add a self-service user profile showing global/store roles and effective permissions, plus secure password change and Arabic/English account language switching.
- Remove conflicting legacy sidebar and mixed-domain management UI; SUPER_ADMIN no longer receives Retail operational navigation outside explicit Retail support context.
- Replace banner image-path text entry with real verified image upload, preview, replacement and file cleanup inside the Retail content workspace.
- Seed three premium merchandising banners per demo Retail store with reusable visual assets.
- Upgrade Customer mobile visual system and Retail journey surfaces for a premium e-commerce presentation while preserving API, routing and checkout rules.
- Upgrade Driver mobile home, search/filter surfaces and delivery task cards for a professional field-operations experience.
- Keep Wholesale/Retail authorization, tenant boundaries and all existing business logic unchanged.

## 1.0.13 - Premium Administration and System Inspector
- Apply a shared premium form layer across administration with explicit field labels, descriptive placeholders, consistent controls, image previews and responsive spacing without changing business rules.
- Show clear Bootstrap-style success/error modals after admin mutations and validation failures while retaining server-authoritative validation.
- Add semantic icons to administration tabs and convert retail-store provisioning into a two-step tabbed flow for store details and manager assignment.
- Add the System Inspector to the administration sidebar to capture server, route, JavaScript and Fetch failures with correlation, user and store context.
- Add downloadable JSON diagnostics with runtime/storage health so deployment problems can be shared for troubleshooting.
- Harden catalog/category/brand image persistence by verifying bytes on the public disk, rolling back orphaned files on database failure and preserving safe image replacement/deletion.
- Make the installer guarantee the public storage link required by uploaded media, with an Inspector repair action for existing deployments.
- Preserve Wholesale/Retail tenant boundaries, permissions and the existing business model.

## 1.0.12 - Administration Navigation and Tenancy Audit
- Keep SUPER_ADMIN on the platform control plane by default and require explicit audited Retail store inspection before entering a Retail tenant workspace.
- Remove duplicate and legacy mixed-domain administration entry points that could conflict with the authoritative Wholesale/Retail business model.
- Make all B2B and Retail workspace navigation permission-aware so links are not shown when the current user cannot open the destination.
- Keep product/catalog actions in catalog management and inventory/warehouse actions in inventory management.
- Add persistent user logout to the shared administration sidebar.
- Complete Arabic administration copy cleanup so Arabic screens do not show English helper text where a proper Arabic label exists.
- Add descriptive placeholders to administration text fields and a shared fallback for newly introduced fields.
- Preserve the 1.0.11 Demo Data dashboard workflow and permission/audit safeguards.

## 1.0.11 - Demo Data Admin Access Fix
- Add a dedicated `/admin/security/demo-data` management page instead of relying on a command-line seeder workflow.
- Allow authorized `demo_data.manage` operators to create/rebuild and clear isolated FOODEX-DEMO records from the dashboard, including on the deployed environment.
- Keep demo-data mutations audit logged and cleanup restricted to FOODEX-DEMO markers and the `demo.foodex.test` namespace.
- Add the Demo Data page to administration navigation and provide Arabic/English UI copy.
- Keep dashboard update generation cumulative from FOODEX 1.0.6 so 1.0.6 through 1.0.10 installations can upgrade directly.

## 1.0.10 - Login-first Home Entry
- Redirect the public FOODEX root URL directly to the canonical Retail management login.
- Keep the installer endpoint and authenticated management workspaces unchanged.
- Add regression coverage that keeps the root login-first even when a browser already has an authenticated management session.
- Allow dashboard update bundles with no database migrations and report `contains_migrations=false` accurately for routing/UI-only patch releases.

## 1.0.9 - Retail Replenishment from Wholesale
- Represent every Retail store as a managed active Wholesale customer account, including safe backfill for existing stores and automatic linking for new stores and demo fixtures.
- Keep linked Wholesale account identity/status synchronized with the Retail store and prevent manual account-state drift.
- On a linked Retail customer's delivered B2B order, atomically consume Wholesale reservations and receive the exact ordered quantities into that Retail store only.
- Materialize tenant-owned Retail product/category/brand/unit records from the Wholesale source while preserving SKU, order-name snapshot, description, hierarchy, images and lookup metadata without sharing tenant-owned rows.
- Record purchase cost separately from Retail selling price, preserve an existing Retail selling price, and initialize a new Retail selling price from the received unit cost.
- Add idempotent replenishment and line-level provenance records so delivery retries cannot duplicate stock.
- Surface Retail stores clearly in the Wholesale customer picker and show purchase cost separately in Retail product management.
- Add end-to-end regression coverage for exact-store receiving, cross-store isolation, account provisioning and repeat-delivery idempotency.

## 1.0.8 - Wholesale / Retail Isolation Hardening
- Make global operational roles wholesale-only and introduce store-scoped Retail Operations, Inventory, Finance and Customer Support roles.
- Enforce Retail-only `user_store_roles` and reject attempts to assign store-scoped roles to wholesale stores.
- Make permission resolution channel-aware so a global wholesale permission can never authorize a Retail store and a Retail role can never authorize another store.
- Preserve existing Retail assignments through a migration while cleaning invalid cross-channel role assignments.
- Keep lookup visibility and mutations isolated to platform-global, wholesale, or the exact assigned Retail store.
- Remove user-facing B2C wording in favor of `التجزئة` / `Retail` while retaining internal `b2c` route/API/database identifiers for compatibility.
- Add regression coverage for wholesale-vs-Retail isolation, Retail-store-to-Retail-store isolation, RBAC assignment boundaries and visible terminology.

## 1.0.7 - Retail Admin Isolation and Catalog Reliability
- Complete the B2C retail-admin surface audit so store managers enter their own retail dashboard and remain scoped to assigned stores.
- Restrict platform store management to the platform owner while preserving tenant-scoped product, category, inventory, customer, promotion, content, driver and reporting workflows.
- Add role-scoped Brands & Units management with validated brand image upload and grid thumbnails.
- Fix catalog product editing for legacy references and tenant-owned dropdown data to prevent 422 failures.
- Harden promotional notification targeting so retail admins cannot address users from another store or mismatch customer/driver app audiences.
- Refresh the tester distribution from the current main source and build the dashboard update cumulatively from the previous VERSION boundary.

## 1.0.6 - Multi-Tenant Operations, Orders, Campaigns and Catalog Media
- Complete the Multi-Store / Multi-Tenant architecture with isolated B2B wholesale and B2C retail workspaces, tenant-owned catalogs, customer-domain separation, scoped lookups and retail-store provisioning.
- Add complete dashboard order management for B2B and B2C including manual creation, pending-order editing, pricing, discounts, delivery fees, payment records, inventory reservations, lifecycle transitions and driver assignment.
- Add complete driver order execution with assigned/active/completed/failed views, customer/address/item/payment details, delivery actions, failure reasons, retry flow and authoritative order-status synchronization.
- Add scheduled promotional notification campaigns for B2B and B2C with one-time and recurring schedules, pause/resume/cancel, tenant-scoped audiences, Firebase delivery, run history and duplicate-dispatch protection.
- Add product galleries and dedicated category images with upload/change/remove controls, primary image ordering, tenant-safe storage, API image URLs and Customer mobile rendering.
- Wire production Android Firebase client configuration for Customer and Driver package IDs.
- Preserve non-destructive upgrade compatibility from FOODEX 1.0.5; this update contains database migrations.

## 1.0.5 - Operational Admin CRUD
- Add tenant-scoped B2C order transition, driver assignment and inventory adjustment controls directly from operational workspaces.
- Seed safe default product units and B2B price tiers for fresh installations and upgrades.
- Preserve the newer catalog/business management centers while integrating the remaining RTL-admin CRUD work from PR #235.
- Add a central Operations & Data Management center for warehouses, stock, customers, promotions, banners and drivers.
- Add create/edit/delete/deactivate actions with server-side permission checks and dependency safety.
- Wire B2C Inventory, Customers, Promotions, Content and Drivers screens to their management actions.
- Keep inventory adjustments above reserved stock and record stock movements.

## 1.0.4 - Admin CRUD and Arabic RTL
- Fix the unified Arabic admin shell so the navigation sidebar renders on the physical right while English remains left-to-right.
- Add a server-authorized Catalog & Store Management center with product, category, brand, unit and store administration.
- Add product create/edit/delete/deactivate behavior, store assignment and pricing, and category create/edit/delete with dependency protection.
- Wire dashboard and B2C product actions to the management center and add regression coverage for fresh CRUD workflows.

## 1.0.3 - First-install Admin Access
- Redirect unauthenticated management requests to the admin login instead of a raw 401 page.
- Allow the installer-created `SUPER_ADMIN` to open B2C management surfaces before any stores exist.
- Add regression coverage that verifies the fresh-install Super Admin can open every management GET surface without permission errors.

## 1.0.2 - MySQL/MariaDB Production Installer
- Switch production and first-run database configuration to MySQL/MariaDB for cPanel hosting.
- Require `pdo_mysql`, default to port 3306, and retain PostgreSQL runtime compatibility for legacy installations.
- Add MySQL backup/restore support to the updater and validate deployment recovery against MySQL + Redis.
- Refresh the first-install setup bundle so `foodex.50sols.com` can install directly against `solscool_foodex`.

## 1.0.0 - Release Candidate
- Complete FOODEX Web administration for B2B and B2C with scoped data, permissions, reporting, governance, localization and audited actions.
- Complete Customer mobile B2B/B2C journeys for authentication, catalog, pricing, cart/checkout, orders, invoices, account data, profile and version policy.
- Complete Driver mobile B2B/B2C delivery journeys with task lifecycle, empty/offline handling and bilingual RTL/LTR presentation.
- Production-readiness foundations include installer/updater recovery, PostgreSQL/Redis acceptance, OpenAPI/security validation, observability and release-mode mobile validation artifacts.
- FOODEX brand system is applied across Web, Customer and Driver surfaces.
- Runtime evidence pack contains 110 real screenshots covering all 46 approved baseline screens plus Driver states in Arabic and English.
- Production deployment, signed mobile distribution and final physical-device acceptance remain gated by the external inputs tracked in #124 and #125.

## 0.1.0 - Bootstrap
- Monorepo and architecture foundation.
- Laravel/API/admin foundation.
- Customer and Driver Flutter foundations.
- PostgreSQL schema foundation.
- OpenAPI/installer/updater foundations.
- CI/tests/governance/design-reference indexing.
