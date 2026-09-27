# Changelog

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
