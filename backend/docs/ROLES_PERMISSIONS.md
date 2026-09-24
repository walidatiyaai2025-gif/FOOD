# Roles and permissions

FOODEX authorization is enforced by the backend. Client applications may use role/permission information to render navigation, but client-side checks are never authoritative.

## Built-in roles

- `SUPER_ADMIN`: platform-wide access to every declared FOODEX permission.
- `B2B_ADMIN`: B2B account, pricing, catalog, inventory, order, finance, reporting and B2B-driver administration.
- `B2C_STORE_ADMIN`: retail-store administration. Its permissions require an explicit store assignment and store context.
- `OPERATIONS`, `INVENTORY`, `FINANCE`, `CUSTOMER_SUPPORT`: bounded operational roles.
- `B2B_DRIVER` and `B2C_DRIVER`: distinct execution roles. Neither role grants the other channel's delivery permission.

The canonical permission names and built-in mapping live in `config/permissions.php`. `CoreReferenceSeeder` inserts the declared permissions and their role mappings idempotently.

## Enforcement

Laravel Gate abilities are registered for every declared permission. Global roles are resolved from `role_user`. Store-scoped roles are resolved from `user_store_roles` only when a store ID is supplied to the authorization check.

Store query isolation, cross-store denial and reusable store policies are intentionally handled by Issue #12. Issue #11 establishes the role/permission boundary without duplicating store-scoping logic.
