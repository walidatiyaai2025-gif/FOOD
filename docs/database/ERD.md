# Database Foundation / ERD

Production DB: PostgreSQL, selected for transactional integrity, mature constraints/indexing, JSON support for bounded metadata and reliable concurrent commerce workloads.

## Entity groups

- Identity: users, roles, permissions, role_user, permission_role.
- Stores: store_types, stores, user_store_roles, warehouses.
- Catalog: categories, brands, units, products, product_images, store_products.
- Inventory: inventories, stock_movements.
- Customers/B2B: customers, b2b_accounts, b2b_price_tiers, addresses.
- Commerce: carts, cart_items, orders, order_items, order_status_history.
- Finance: invoices, invoice_items, payments.
- Marketing: promotions, coupons, banners.
- Delivery: drivers, driver_assignments, delivery_proofs.
- Platform: notifications, app_versions, system_versions, update_history, audit_logs, settings.

## Key invariants

- stores belong to store_types.
- user_store_roles binds user + store + role and is the authoritative B2C admin scope.
- store_products activates/prices a product per store.
- inventory is unique per warehouse/product.
- B2B account belongs to a customer and optional price tier.
- every order belongs to one store and one customer and has explicit B2B/B2C channel.
- driver assignment type must match both driver type and order channel.
- settings may be platform or store scoped; secret values never leave privileged server paths.

Executable schema foundation lives in `backend/database/migrations`.
