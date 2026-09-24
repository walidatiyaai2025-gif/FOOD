# FOODEX Backend Runtime Baseline

Issue #3 defines the Laravel runtime foundation only. Product features remain issue-owned work.

## Runtime entry points

- `GET /health` — Laravel health probe.
- `GET /api/v1/version` — stable API v1 runtime/version contract.
- `GET /` — FOODEX bootstrap identity endpoint.
- `GET /install` — first-run installer foundation while no install lock exists.

## Runtime conventions

- Laravel 12 on PHP 8.2+.
- Production data target: PostgreSQL.
- Cache and queues target: Redis.
- Application timezone: UTC.
- Arabic is the primary locale; English is the fallback locale.
- API routes are versioned under `/api/v1`.
- Domain code remains separated under `app/Domain/*`; feature behavior must be introduced only by its owning Issue.

## Required validation

From `backend/`:

```bash
composer test
composer lint
composer analyse
composer audit
```

Pull requests that change `backend/**` are also validated by the repository's `required-ci-gate`, including migration/seed validation and OpenAPI linting.
