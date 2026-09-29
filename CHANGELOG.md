# Changelog

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
