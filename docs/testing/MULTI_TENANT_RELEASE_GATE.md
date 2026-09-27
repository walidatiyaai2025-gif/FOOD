# FOODEX Multi-Tenant Release Gate

Issue #247 is the repository release gate for the Multi-Store / Multi-Tenant architecture defined in `docs/architecture/MULTI_TENANT_ARCHITECTURE.md`.

Closing this gate means the repository has executable evidence that existing pre-multi-tenant data can be upgraded without a destructive reset and that tenant/channel ownership is enforced across the supported administration and operational paths. It does **not** replace the external production deployment evidence tracked by #124.

## Required evidence

| #247 acceptance requirement | Executable evidence |
|---|---|
| Pre-multi-tenant upgrade without reset | `tests/Deployment/MultiTenantUpgradeTest.php` builds only the schema that predates the first multi-tenant migration, inserts legacy stores/catalog references/customers/orders/inventory, then runs the normal pending migration chain on MySQL. |
| Catalog/product reconciliation | `MultiTenantUpgradeTest` verifies a product formerly shared by two stores is split into independently owned catalogs and that store-product and inventory references are rewired to the correct owner. |
| B2B/B2C customer reconciliation | `MultiTenantUpgradeTest` verifies legacy customers remain intact while `b2b_customers` / `b2c_customers` are created and historical B2B/B2C orders plus B2B accounts are backfilled to the authoritative domain IDs. |
| Store A cannot access Store B | `B2cTenantWorkspaceIsolationTest` and `TenantOperationalIsolationTest` cover foreign-store context, inventory, marketing, delivery and mutation tampering. |
| B2C cannot access B2B and B2B cannot access retail data | `B2bWholesaleWorkspaceIsolationTest` covers workspace entry, catalog, inventory, finance and mutation boundaries; `B2cTenantWorkspaceIsolationTest` covers the inverse workspace boundary. |
| Lookup scope isolation | `LookupManagementCenterTest` covers global/B2B/store scope visibility, mutation authorization, duplicate rules and cross-channel lookup-ID tampering. |
| Store provisioning -> manager tenant access | `RetailStoreProvisioningTest` proves SUPER_ADMIN provisioning and B2C_STORE_ADMIN assignment/revocation; `B2cTenantWorkspaceIsolationTest` proves the manager resolves only assigned stores. |
| Reports, notifications and audit preserve tenant context | `TenantOperationalIsolationTest` verifies B2B report filtering, store-scoped notifications, stock movements and audit `store_id`. |
| Backup / failed-upgrade rollback | `tests/Deployment/ProductionServicesTest.php` executes real MySQL dump/restore and file rollback after an injected migration failure. |
| Queue / Redis operational path | `ProductionServicesTest` pushes and processes a real Redis-backed queued job. |

## CI gate

The reusable Backend CI workflow executes:

- all backend feature tests via `composer test`;
- deployment tests under `tests/Deployment` against disposable MySQL 8 and Redis 7;
- fresh migrations, deterministic seed validation, lint, static analysis, Composer audit and OpenAPI validation;
- `required-ci-gate` waits for the backend workflow, including the MySQL/Redis deployment acceptance job.

The migration rehearsal is intentionally guarded by `FOODEX_DISPOSABLE_SERVICES=true` and database name `foodex_acceptance`. It must never run against production data.

## Release boundary

When this gate is green, FOODEX may be described as **Multi-Tenant ready at repository/CI level**.

Production promotion still requires the environment-specific items tracked by #124, including the real prior supported release package, target environment coordinates, production/staging backup identifier, secrets/signing inputs, post-deploy health evidence and final device acceptance. Those inputs are intentionally not fabricated by CI.
