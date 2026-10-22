# FOODEX Legacy 4× Performance Gate

Issue: #2274

This gate extends the existing Enterprise performance infrastructure instead of creating a second
benchmark framework. It is intentionally isolated from production data and runs against a disposable
MySQL database.

## Scale model

The reproducible baseline and scaled profiles are defined in
`Database\\Seeders\\EnterprisePerformanceSeeder`.

| Metric | legacy1x | legacy4x |
|---|---:|---:|
| Products | 600 | 2,400 |
| Customers | 3,600 | 14,400 |
| Orders | 12,500 | 50,000 |
| Order items | 187,500 | 750,000 |
| Stock movements | 62,500 | 250,000 |
| Finance records | 25,000 | 100,000 |
| Audit/events/messages | 62,500 | 250,000 |

The 4× profile therefore covers the issue's approximately 4× legacy-volume requirement, including
the cited ~187k scanned sales-detail scale and ~24,950 finance-row baseline.

## Workload

Each HTTP round uses 50 concurrent authenticated users:

- 25 active Driver users posting real `/api/v1/driver/location/heartbeat` traffic;
- 25 SUPER_ADMIN office users distributed across dashboard, inventory, management-report,
  B2B-account and live-tracking read paths;
- every workload user authenticates through the real `/api/v1/auth/login` flow before measured
  traffic.

The load runner is `scripts/foodex-legacy-scale-load.py`. It uses only Python's standard library so
the same runner can execute in GitHub Actions or against an isolated staging host.

## Acceptance thresholds

The scaled profile enforces the #2274 limits:

- ordinary/field/inventory/B2B/tracking paths: p95 <= 800–1000 ms;
- common dashboard path: p95 <= 1000 ms;
- bounded management reports: p95 <= 3000 ms;
- total error rate < 1%;
- 50 concurrent users and at least 25 field users are mandatory.

The DB evidence runner `scripts/foodex-legacy-scale-db-evidence.php` additionally records p50/p95/p99,
slow-query fingerprints and EXPLAIN plans for representative growing-table reads. Repeated queries at
or above 250 ms or an unindexed full scan of a declared growing table fail the DB evidence gate.

## CI evidence

`.github/workflows/legacy-scale-performance-gate.yml` performs:

1. schema migration on disposable MySQL;
2. 1× baseline seed and 50-user benchmark;
3. clean reseed to 4× volume;
4. the same 50-user benchmark;
5. slow-query and EXPLAIN capture;
6. baseline-versus-4× comparison;
7. artifact upload containing fixture manifests, raw HTTP metrics, DB evidence, server logs and the
   final Markdown report.

The workflow never runs destructive commands against an existing persistent FOODEX database. The
performance seeder remains blocked outside local/testing unless `FOODEX_PERF_ALLOW_SEED=1` is
explicitly set for an isolated benchmark database.

## Local reproduction

From the repository root with an isolated MySQL database configured in `backend/.env`:

```bash
cd backend
php artisan migrate --force
FOODEX_PERF_PROFILE=legacy1x FOODEX_PERF_ALLOW_SEED=1 \
  php artisan db:seed --class=Database\\Seeders\\EnterprisePerformanceSeeder --force
```

Start FOODEX, then run:

```bash
python3 scripts/foodex-legacy-scale-load.py \
  --profile legacy1x \
  --output artifacts/legacy-scale/legacy1x-http.json
```

Repeat with `FOODEX_PERF_PROFILE=legacy4x`, then capture DB evidence and compare:

```bash
php scripts/foodex-legacy-scale-db-evidence.php \
  artifacts/legacy-scale/legacy4x-db-evidence.json

python3 scripts/foodex-legacy-scale-compare.py \
  artifacts/legacy-scale/legacy1x-http.json \
  artifacts/legacy-scale/legacy4x-http.json \
  --json-output artifacts/legacy-scale/comparison.json \
  --markdown-output artifacts/legacy-scale/report.md
```

Issue #2274 is considered complete only when the exact PR head has a green legacy-scale workflow and
the ordinary repository-required CI gate is also green.
