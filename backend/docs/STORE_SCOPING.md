# Store scoping

Store authorization is backend-owned. A client-supplied store ID is only a resource selector and is never proof of access.

## Rules

- `SUPER_ADMIN` has platform-wide store access.
- Every other authenticated user is restricted to stores present in `user_store_roles`.
- `B2C_STORE_ADMIN` therefore sees only explicitly assigned stores.
- An unassigned user has no store access.
- Store-owned queries use the reusable `accessibleTo($user)` Eloquent scope.
- Direct store-owned models use `ScopesStoreAccess`; inventory resolves access through its warehouse.
- `StorePolicy::view` applies the same authoritative rule for individual store access.
- Public B2C browsing is a separate product flow and does not use authenticated admin scope as a substitute for catalog visibility rules.

## Covered store-owned models

Direct scope: stores, warehouses, store products, carts, orders, promotions, banners and settings.

Indirect scope: inventories through warehouses.

Master product/category/brand/unit records are platform-global. Their per-store availability and pricing are represented by `store_products`.

Role/permission checks remain additive. Passing a permission check does not bypass store scope, except for the documented `SUPER_ADMIN` platform role.

Feature coverage creates two retail stores and proves that a `B2C_STORE_ADMIN` assigned to one store cannot retrieve the other store or its owned rows through these scopes. It also verifies the individual `StorePolicy`, unassigned denial, indirect inventory scoping and the Super Admin exception.
