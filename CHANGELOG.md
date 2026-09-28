# Changelog

## 1.0.19 - Retail Administration Production Fixes
- Harden production updates from System Inspector findings: automatically restore `public/storage`, suppress invalid Retail-linked Wholesale status mutations, and hide unpriced B2B customers from order creation.
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
