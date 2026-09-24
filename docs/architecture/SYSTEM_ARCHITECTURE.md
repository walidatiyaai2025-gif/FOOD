# FOODEX System Architecture

FOODEX is one platform with one Laravel backend/API/admin web application and exactly two Flutter applications.

## Runtime boundaries

1. Laravel is the single source of truth for authentication, authorization, store scoping, pricing, catalog, inventory, orders, delivery, payments, reporting, installer/updater state and mobile APIs.
2. The Management Web Dashboard is one role-based Laravel web system. Do not create duplicate B2B/B2C admin applications.
3. The Customer App is one Flutter binary for B2C Guest, B2C Customer and approved B2B Customer experiences.
4. The Driver App is one Flutter binary for B2C_DRIVER and B2B_DRIVER; authorization and assignment queries must keep the channels separate.
5. PostgreSQL is the production relational database. Redis backs queues/cache.
6. REST /api/v1 is the public application contract; OpenAPI is canonical.

## Dependency direction

Web/Flutter UI -> controllers/requests -> policies -> domain actions/services -> models/persistence -> PostgreSQL/Redis.

Mobile clients may own presentation state only. Backend-owned business rules must not be duplicated in Flutter.

## Scoping and audit

Every store-dependent query is store-scoped. B2C Store Admin sees only assigned stores. B2C and B2B driver assignments never cross. Critical mutations require authorization and audit entries.

## Bootstrap scope

Only application roots, domain boundaries, contracts and infrastructure foundations are established now. Product workflows remain issue-scoped implementation work.
