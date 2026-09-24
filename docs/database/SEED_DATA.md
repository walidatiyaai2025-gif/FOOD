# Core Seed Data

FOODEX keeps installation seed data limited to deterministic platform references. Running `DatabaseSeeder` on a fresh database creates:

- Store types: `B2B` and `B2C`.
- The canonical roles defined by the role architecture.
- The permission codes declared in `config/permissions.php`.
- The configured role-to-permission reference mappings.

The seed deliberately does **not** create users, stores, products, orders, customers, or other demo business records.

## Idempotency contract

The reference seed can run repeatedly. Re-running it keeps the same reference IDs, timestamps, row counts, and role/permission mappings when the configured reference data has not changed. Reference names may be corrected by a later code release without replacing their stable IDs.

This behavior is covered by `Tests\Feature\SeedDataTest` and is part of the fresh-install validation path.
