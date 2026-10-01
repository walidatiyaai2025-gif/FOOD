# FOODEX Assistant V1 — cPanel Rollout and Rollback

This procedure covers the Assistant V1 repository integration on the existing FOODEX Laravel/cPanel runtime. It introduces no paid AI service, API key, persistent model process, Python daemon, Node daemon, vector database, or GPU dependency.

## Runtime contract

Assistant V1 uses the existing FOODEX components only:

- PHP/Laravel application runtime;
- existing MySQL/MariaDB connection;
- existing Laravel session/authentication and RBAC;
- existing Laravel cache infrastructure (no Assistant business-result cache is active in V1);
- existing Laravel scheduler infrastructure (no Assistant-specific scheduled task is required in V1).

No additional cPanel service or process supervisor entry is required.

## Safe production defaults

Keep these values when deploying the code unless a separate, explicit rollout decision authorizes activation:

```env
ASSISTANT_ENABLED=false
ASSISTANT_READ_ONLY=true
ASSISTANT_RETENTION_DAYS=90
ASSISTANT_CACHE_SECONDS=60
ASSISTANT_RATE_LIMIT=30
```

`ASSISTANT_ENABLED=false` is the production safety fence. The code, migrations, tests, UI assets and routes may be deployed while normal FOODEX workflows remain unaffected and the Assistant UI/functionality stays unavailable.

`ASSISTANT_READ_ONLY=true` is mandatory for V1. The Assistant controller rejects operation if the read-only safety flag is disabled.

## Pre-deploy

Record real environment evidence outside the repository:

1. current deployed commit/version;
2. database backup identifier and completion result;
3. application/files backup identifier when applicable;
4. target cPanel account/path and PHP binary;
5. rollback decision owner.

Do not invent these values in GitHub issues or release notes.

## Deploy while disabled

From the existing FOODEX Laravel backend directory, use the same PHP binary and deployment conventions already approved for the environment. A typical shell sequence is:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then verify:

```bash
php artisan about
php artisan migrate:status
```

Repository migrations add isolated Assistant conversation/state tables and the `assistant.use` permission. They do not require an external AI service.

With `ASSISTANT_ENABLED=false`, confirm normal management pages continue to operate and the Assistant entry is not rendered.

## Optional activation after explicit approval

Activation is a separate operational decision. Do not couple it to code deployment.

Set:

```env
ASSISTANT_ENABLED=true
ASSISTANT_READ_ONLY=true
ASSISTANT_RATE_LIMIT=30
```

Then refresh cached configuration:

```bash
php artisan optimize:clear
php artisan config:cache
```

Smoke-check with an authorized management user:

1. Assistant entry appears in the management sidebar;
2. create a conversation;
3. ask a deterministic supported question such as `Sales today`;
4. confirm response scope matches the user's B2B/B2C/store permissions;
5. confirm a forged or foreign store/entity context is rejected;
6. confirm no business-domain record is modified;
7. confirm normal Orders/Stores/Customers/Drivers workflows continue independently.

## Scheduler and cache

Assistant V1 requires no new cron entry and no scheduled precomputation.

If the FOODEX installation already uses Laravel Scheduler, keep its existing `schedule:run` contract unchanged. Do not add an Assistant-specific daemon.

Assistant V1 does not currently write/read cached tool-result payloads. `ASSISTANT_CACHE_SECONDS` is retained as a future configuration seam only. A future cache implementation must add scope-complete cache keys and invalidation/isolation tests before activation.

## Fast Assistant-only rollback

If an Assistant-specific runtime problem appears, disable the feature first:

```env
ASSISTANT_ENABLED=false
ASSISTANT_READ_ONLY=true
```

Then:

```bash
php artisan optimize:clear
php artisan config:cache
```

This is the preferred first rollback because it leaves normal FOODEX modules available and preserves Assistant conversation data for diagnosis.

## Full application rollback

If a full code rollback is required:

1. keep `ASSISTANT_ENABLED=false`;
2. enter the environment's normal maintenance/backup procedure;
3. redeploy the previously approved FOODEX commit/package;
4. restore the database only when the recorded rollback plan requires it;
5. rebuild Laravel caches;
6. run normal health checks before exiting maintenance.

Do not run destructive migration rollback against a production database merely to hide the Assistant. Feature disablement is sufficient for Assistant isolation. Database rollback/restoration must follow the environment's recorded backup/recovery procedure.

## Evidence boundary

Repository CI proves code-level install/upgrade/recovery behavior on disposable services. The following remain real deployment evidence and must be recorded by the deployment owner if/when production rollout occurs:

- actual cPanel backup identifiers;
- actual production migration result;
- actual production HTTPS/session smoke result;
- actual enablement decision;
- actual post-deploy health/rollback decision.

Assistant repository completion must not be blocked by fabricating or waiting on those external values while the feature remains disabled by default.
