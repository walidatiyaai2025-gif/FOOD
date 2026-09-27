# FOODEX System Architecture

FOODEX is one platform with one Laravel backend/API/admin web application and exactly two Flutter applications.

## Runtime boundaries

1. Laravel is the single source of truth for authentication, authorization, store scoping, pricing, catalog, inventory, orders, delivery, payments, reporting, installer/updater state and mobile APIs.
2. The Management Web Dashboard is one role-based Laravel web system. Do not create duplicate B2B/B2C admin applications.
3. The Customer App is one Flutter binary for B2C Guest, B2C Customer and approved B2B Customer experiences.
4. The Driver App is one Flutter binary for B2C_DRIVER and B2B_DRIVER; authorization and assignment queries must keep the channels separate.
5. MySQL/MariaDB is the production relational database for the current cPanel deployment. Cache/queue backends are environment-configurable; backend business rules must not depend on a specific cache driver.
6. REST /api/v1 is the public application contract; OpenAPI is canonical.

## Dependency direction

Web/Flutter UI -> controllers/requests -> policies -> domain actions/services -> models/persistence -> PostgreSQL/Redis.

Mobile clients may own presentation state only. Backend-owned business rules must not be duplicated in Flutter.

## Scoping and audit

The authoritative tenancy contract is `docs/architecture/MULTI_TENANT_ARCHITECTURE.md`.

- The platform owner is the wholesale/B2B principal.
- Every B2C retail store is an isolated tenant.
- Every store-dependent query is scoped through the authoritative StoreContext/TenantContext.
- B2C Store Admin sees only explicitly assigned stores.
- B2B wholesale and B2C retail customer/catalog/operational domains never cross.
- B2C and B2B driver assignments never cross.
- Critical mutations require authorization, ownership validation and audit entries.
- SUPER_ADMIN support access across stores is explicit/auditable; unscoped application queries are prohibited.

## Bootstrap scope

Only application roots, domain boundaries, contracts and infrastructure foundations are established now. Product workflows remain issue-scoped implementation work.
