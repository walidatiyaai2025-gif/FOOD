# Enterprise Performance Fixture

Issue: #1502  
Parent: #1488

The fixture is explicit and isolated from normal product seed data.

Run from `backend/` against an isolated benchmark database:

```bash
FOODEX_PERF_PROFILE=smoke php artisan db:seed --class=Database\\Seeders\\EnterprisePerformanceSeeder
FOODEX_PERF_PROFILE=standard php artisan db:seed --class=Database\\Seeders\\EnterprisePerformanceSeeder
FOODEX_PERF_PROFILE=heavy FOODEX_PERF_ALLOW_SEED=1 php artisan db:seed --class=Database\\Seeders\\EnterprisePerformanceSeeder
```

Outside `local` / `testing`, the seeder fails closed unless `FOODEX_PERF_ALLOW_SEED=1` is set.
That override is only for an isolated certification database.

Profiles are deterministic and chunked. Re-running a profile removes only rows carrying the
`PERF-` fixture markers and rebuilds the fixture. The heavy profile provides at least:

- 100,000 orders;
- 500,000 order items;
- 250,000 stock movements;
- 100,000 finance rows (invoices + payments);
- 250,000 audit rows;
- multiple stores and warehouses.

Current-main tenant note: before Wave 0 (#1487), FOODEX has store-scoped tenancy but no canonical
`tenants` table. The fixture therefore models three explicit logical tenant groups in store codes
(`PERF-Txx-Sxx`) and records `logical_store_groups_pre_wave0` in the manifest. Final Enterprise
certification must bind these logical groups to the canonical organization/tenant model delivered by
Wave 0; this lane does not invent a competing tenant schema.

The generated manifest is:

`storage/app/private/enterprise-performance/fixture-manifest.json`

It records target and actual counts and fails seeding if they differ.
