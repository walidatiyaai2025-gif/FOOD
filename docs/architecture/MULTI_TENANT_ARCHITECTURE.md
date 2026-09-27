# FOODEX Multi-Store / Multi-Tenant Architecture

Status: **Authoritative**
Owner: FOODEX Coordinator
Approved: 2026-09-27

This document defines the required tenancy model for FOODEX. New implementation work must not preserve the current accidental sharing of catalog/customer/master data where that sharing violates these boundaries.

## 1. Business ownership model

FOODEX has one platform owner and two clearly separated commerce domains:

1. **Platform owner / wholesale principal (B2B).**
   - The owner of the FOODEX platform is the wholesaler.
   - The wholesale business owns its own B2B catalog, categories, products, warehouses, inventory, wholesale customers, pricing, orders, finance, drivers and reports.
   - B2B management is not a retail-store tenant workspace.

2. **Retail B2C stores are isolated tenants.**
   - Each retail store is provisioned by SUPER_ADMIN.
   - Each store has one or more explicitly assigned store-scoped users/roles.
   - A B2C Store Admin manages only the assigned retail store(s).
   - One retail store must never read, mutate, aggregate or export another retail store's tenant data.

3. **SUPER_ADMIN is the platform control plane.**
   - SUPER_ADMIN creates/activates/deactivates retail stores.
   - SUPER_ADMIN creates/selects a user and assigns a store-scoped role through `user_store_roles`.
   - SUPER_ADMIN sees a Store Directory and platform-level health/administration.
   - SUPER_ADMIN may intentionally enter an explicit store context for support/audit, but retail daily operations must not be mixed into the platform-global context.

## 2. Mandatory tenant root and StoreContext

`stores` remains the ownership root for store-dependent data.

The backend must expose one authoritative StoreContext/TenantContext service that resolves:
- authenticated user;
- requested channel (`b2b` or `b2c`);
- active store;
- permitted store IDs;
- effective store-scoped role/permissions.

Rules:
- Controllers must not trust arbitrary `store_id` input.
- Policies/services/query objects must validate the requested entity belongs to the active/permitted store.
- Cross-store IDs return 403/404 and never leak existence/details.
- SUPER_ADMIN bypass is explicit and auditable, not an implicit unscoped query.
- Background jobs, exports, notifications, reports and API endpoints carry the same store context.
- Menu visibility is presentation only; server authorization remains mandatory.

## 3. Catalog ownership model

The current global `categories` and `products` model is transitional and must be replaced.

Target hierarchy:

```text
Store
  -> Catalog
      -> Categories
      -> Products
          -> Product Images
          -> Store/price availability
```

Requirements:
- Add `catalogs` owned by exactly one `store_id` and channel.
- Every category belongs to exactly one catalog.
- Every product belongs to exactly one catalog.
- A product cannot be attached to another store's warehouse, price rule or storefront.
- SKU uniqueness is scoped to catalog/store, not globally.
- Category slug uniqueness is scoped to catalog, not globally.
- Parent categories must belong to the same catalog.
- B2B wholesale catalog data never appears in a B2C store catalog.
- B2C Store A catalog data never appears in B2C Store B.
- Migration must preserve production data and IDs/references where practical; no reset is allowed.

## 4. Customer domain separation

The current shared `customers` table with a `type` column is not the target architecture.

Target persistence boundaries:
- `b2b_customers` — wholesale customers/companies owned by the platform wholesale domain.
- `b2c_customers` — retail customers scoped to `store_id`.

Rules:
- B2B and B2C customer records have separate repositories/services/policies.
- B2B account/company fields and price-tier/credit data never live on B2C customer records.
- B2C customer data is store scoped. The same email/phone may exist in different B2C stores when allowed by business rules.
- Orders, addresses, favorites, invoices/statements and reports must reference the correct customer domain and preserve channel/store invariants.
- Customer authentication identity may remain in the shared `users` identity table, but business customer records remain separate.
- No API or admin query may combine B2B and B2C customer rows without an explicit SUPER_ADMIN/platform report contract.

## 5. Inventory and operational isolation

Warehouses and inventory are store-owned:
- every warehouse belongs to one store;
- inventory may contain only products from that store's catalog;
- stock movements inherit the warehouse/store boundary;
- B2B warehouse stock and B2C store stock are independent.

The same isolation applies to:
- orders and carts;
- promotions/offers;
- banners/storefront content;
- drivers and delivery assignments;
- settings;
- notifications;
- reports/exports;
- audit records and operational dashboards.

A query filtered only by `channel` is insufficient when `store_id` ownership also exists.

## 6. Store provisioning flow

SUPER_ADMIN control-plane flow:

```text
Stores
  -> Create Retail Store
  -> Configure identity / status / locale / defaults
  -> Create or select manager user
  -> Assign B2C_STORE_ADMIN to this store only
  -> Optionally assign narrower store roles
  -> Activate store
```

The resulting B2C Store Admin login must:
- resolve the assigned store automatically when only one store is assigned;
- show an authorized store switcher only when that user manages multiple retail stores;
- never show B2B wholesale administration;
- never show other retail tenants.

SUPER_ADMIN may list all retail stores and manager assignments, but the default platform dashboard remains a control plane, not a merged operational workspace.

## 7. B2B wholesale workspace

The platform owner is the wholesale merchant.

The B2B workspace is independent and contains:
- wholesale catalogs/categories/products/brands;
- wholesale warehouses/inventory;
- B2B customers/accounts;
- price tiers, price rules and approvals;
- wholesale carts/orders;
- invoices, statements and finance;
- B2B drivers/delivery;
- B2B reports/settings.

B2B_ADMIN permissions apply only to the wholesale domain. B2C_STORE_ADMIN permissions never grant B2B access.

## 8. B2C tenant workspace

Each retail tenant gets a complete independent management workspace:
- Dashboard;
- Catalogs;
- Categories;
- Products;
- Brands visible/allowed for that store;
- Warehouses/Inventory;
- Orders;
- Retail Customers;
- Promotions;
- Banners/Storefront;
- Drivers/Delivery;
- Reports;
- Store Settings.

All create/edit/delete actions must derive store ownership from StoreContext rather than a client-supplied unrestricted store ID.

## 9. Lookup Management Center

Any business-managed lookup/master data must have one discoverable management screen instead of hidden seed-only tables or hard-coded admin forms.

Minimum managed lookups:
- Brands;
- Units of measure;
- Store types where business-editable;
- B2B price tiers;
- other configurable lookup sets introduced later.

Lookup records must declare their scope:
- **Platform-global** — e.g. approved units of measure shared for reference.
- **Wholesale/B2B** — owned by the wholesale principal.
- **Retail store-scoped** — owned by a specific B2C store/catalog.

Mandatory Lookup Management capabilities:
- list/search/filter by lookup type and scope;
- add;
- edit;
- activate/deactivate;
- safe delete only when no dependent data exists;
- duplicate/unique validation within the correct scope;
- Arabic/English labels where lookup data is user-visible and localized;
- audit actor/time/change;
- permission checks;
- clear dependency error messages.

Hard state-machine values such as protected order transition states must not become arbitrarily editable lookups unless their business contract explicitly allows it.

## 10. Role and permission model

Required role semantics:
- `SUPER_ADMIN` — platform control plane and explicit audited support access.
- `B2B_ADMIN` — wholesale principal administration only.
- `B2C_STORE_ADMIN` — complete administration only for assigned retail store(s).
- narrower roles such as Inventory/Orders/Catalog/Finance may be assigned per store.

Store-scoped roles must use `user_store_roles`. A global role must not accidentally provide tenant data access unless explicitly designed to do so.

Every mutation requires both:
1. permission for the action; and
2. ownership of the target entity through the resolved StoreContext.

## 11. Migration and compatibility policy

This architecture is applied to the existing production database through versioned migrations.

Mandatory rules:
- no production reset;
- backfill ownership before enforcing non-null constraints;
- preserve historical order/invoice/audit references;
- split legacy customer rows deterministically into B2B/B2C target tables;
- backfill catalogs for existing B2B/B2C stores;
- replace global uniqueness constraints with scoped composite uniqueness;
- retain rollback/backup safety through the Update Center;
- migration scripts must be repeatable/idempotent where the framework contract requires it.

## 12. Acceptance and isolation gates

Release is blocked until automated acceptance proves:
- B2C Store A cannot read/update Store B catalog, inventory, customers, orders, promotions or reports.
- B2C admin cannot access B2B entities or endpoints.
- B2B admin cannot access retail tenant data.
- SUPER_ADMIN can provision a store and assign its manager.
- Assigned manager can fully administer only that store.
- B2B and B2C customers exist in different persistence tables/domains.
- catalog/category/product uniqueness works per owning catalog/store.
- warehouse-product cross-store assignment is rejected.
- Lookup Management performs CRUD with scope/permission/dependency rules.
- exports, notifications, background jobs and reports preserve tenant context.
- upgrade from the supported production version preserves data without reset.
- Arabic RTL and English LTR management flows both pass.
