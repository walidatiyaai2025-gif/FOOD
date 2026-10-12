# Protected FOODEX clean start (#1331)

Canonical surface: Settings Center → FOODEX Reset & Reseed, `/admin/settings/foodex-reset`.
Canonical implementation: `FoodexResetController` → `FoodexResetService` → `LaunchCatalog` adapter.

Only an active SUPER_ADMIN can preview or execute. Every attempt requires the current password, the exact locale-specific confirmation phrase and explicit acknowledgment. A session-bound impact preview expires after five minutes and authorizes one attempt. Changed row content (including changes that preserve row counts) invalidates the preview. UI submission locking, session blocking and a deployment-wide cache lock prevent duplicate/concurrent execution. Multi-node deployments must use a shared lock-capable cache, such as Redis.

## Reset boundary

`FoodexResetService::TABLES` is the explicit deletion allowlist. It covers carts, orders/items/lifecycle/dispatch/proofs, invoices/payments/collection allocations/transactional ledgers, temporary operational/location/notification delivery/read data, catalog/product commercial rules, product images, catalog inventory and banners. It applies across all stores. This is a deliberate fresh-start operation, not ordinary maintenance.

Users/customer identities, authentication credentials/tokens, RBAC, stores/warehouses, provider configuration, platform settings, geographic configuration, notification campaign definitions, migration/release history and historical audit events are preserved. Preserved rows that reference deletion targets block execution. New schema dependencies are never silently added to the deletion allowlist. Foreign keys remain enabled, and deletion order is derived child-first. No TRUNCATE or schema reset is used.

Catalog/API reads are database-backed. The canonical seed contract owns search/derived catalog regeneration. A catalog-generation cache marker advances after commit; a global cache flush is prohibited because it can delete the reset lock. Catalog image files are not erased by reset: source/approved assets remain owned by #1330.

## Canonical seed contract (#1330)

`Database\Seeders\FoodexLaunchSeeder` is the single top-level official seed orchestrator. It must be a Laravel Seeder with:

- `preflight(): array{ready: bool, fingerprint: ?string}`; validation must happen without destructive writes and cover official input completeness, prices/units/store/banner assignments and approved image completeness/quality;
- standard `run()` / `__invoke()`; run inside the caller's existing database transaction, be re-runnable, throw on failure, and rebuild required derived data.

The adapter fails closed until this contract is installed and ready. It does not substitute demo products for official FOODEX data. Seeder and audit failures roll back the reset. Completion audit records counts, catalog fingerprint, operation identity and backup status, never passwords/source rows.

## Production safeguards / #1332 integration

Production is disabled by default. Before a deliberate clean-start deployment:

1. Stop mutation-capable workers/schedulers, enter Laravel maintenance mode, and use its authorized maintenance bypass for the Settings UI.
2. The deployment operator must enable `FOODEX_LAUNCH_RESET_PRODUCTION_ENABLED` in trusted configuration, not a browser parameter.
3. Publish a trusted private preflight record at `storage/app/private/launch-reset-preflight.json` with `ready=true`, `database` matching the active database name, `catalog_fingerprint` matching the canonical seed, and integer Unix `checked_at` no older than five minutes. #1332 owns the actual launch checks/evidence; a typed backup reference is not accepted as proof.
4. Before any deletion, the existing UpdateRuntime must successfully create an actual database backup. Backup failure leaves all rows intact. Preserve the private updater backup directory for rollback.
5. Restore normal configuration after verification. Reset is not the owner's Launch action; #1332 owns Launch and #1333 remains final independent QA.

The feature regression tests use a test-only adapter to verify authorization, full transactional cleanup, rollback, preservation, stale/expired preview, unknown FK dependencies and re-run safety. These tests do not certify the missing official catalog or real production evidence. Final acceptance still requires the dirty sanitized fixture and official seeder from #1330, production-preflight integration, and AR/EN responsive runtime evidence on the final source lineage.
