# FOODEX System Administration Runbook

Issue #1985 converges the existing System Health, System Inspector, Update Center and protected production-dataset restore surfaces. It does not create a second platform administration module or a second backup ledger.

## Canonical entry points

- **System Administration overview:** `/admin/administration/system-health`
- **System Inspector / diagnostic export:** `/admin/inspector`
- **Update Center:** `/admin/settings/system-update`
- **Verified production dataset restore:** `/admin/settings/production-dataset`

All of these remain server-authorized. Dashboard links are navigation only; they do not weaken the underlying permission, confirmation, maintenance-mode or audit gates.

## Health authority

The System Administration overview uses live server-side checks for:

- application/module health;
- database connectivity;
- cache read/write health;
- queue connectivity and failed-job persistence;
- scheduler heartbeat freshness;
- storage writability;
- deployed version and update state;
- pending migrations;
- critical configuration warnings.

System Inspector exposes the same runtime dependency state plus bounded incident data. No credential, Redis password, database password, queue payload or failed-job exception is rendered by the System Administration surfaces.

## Backup authorities and retention

There are two existing backup authorities and they remain distinct:

1. **Production dataset snapshot** — a verified database snapshot used by the protected dataset-switch lifecycle. It is retained until that lifecycle replaces or restores it.
2. **Updater rollback backups** — files plus database when migrations require it. They are retained as rollback evidence; infrastructure cleanup remains operator-controlled.

The Backup Center is a composed view over those authorities. It records type/scope, creation time, size where known, hash/reference, status, retention, reason and originating operation. Raw filesystem paths are private recovery references and are never rendered or exported.

## Restore and rollback safety

Production dataset restore requires the existing SUPER_ADMIN authorization, confirmation phrase, current password and maintenance workflow. Before mutation it verifies:

- backup integrity;
- current database identity;
- deployed release compatibility;
- migration fingerprint compatibility.

After database restoration it re-validates compatibility and runs the authoritative runtime health check before declaring success. Failure remains fail-closed.

System updates verify package integrity, preflight, file backup, database backup integrity when applicable, migrations, cache rebuild and runtime health. On failure the updater restores every available backup, runs a post-rollback health check, and exits maintenance only if rollback and health verification both succeed. Incomplete recovery remains in maintenance and records manual-recovery evidence.

## Failed jobs

The configured `database-uuids` failure driver is backed by the repository-owned `failed_jobs` schema. System Administration and Inspector exports expose only job reference, connection, queue and failure time. Payloads and exception bodies are not exposed.

There is deliberately no generic platform-level **Retry** button. A retry is allowed only from the owning domain workflow when that workflow proves idempotency and preserves its audit/idempotency contract.

## Operator remediation

- **Database unhealthy:** verify reachability and service state without exposing credentials.
- **Cache unhealthy:** verify the configured cache store and connectivity.
- **Queue unhealthy:** verify Redis connectivity and worker supervision.
- **Scheduler stale:** verify the managed cron entry and `storage/logs/scheduler.log`.
- **Storage unwritable:** restore filesystem ownership/write permissions for the application runtime user.
- **Pending migrations:** use the Update Center / approved deployment path; do not run destructive ad-hoc schema changes.
- **Incomplete rollback:** keep maintenance enabled, verify both backups and runtime health, and follow the failed update evidence before explicitly bringing the application back up.

## Export policy

`admin.inspector.export` is the approved System Administration diagnostic export. It includes bounded, allowlisted update/rollback, production snapshot and failed-job evidence. Private backup paths, failed-job payloads/exceptions and unapproved secret-bearing context are excluded.
