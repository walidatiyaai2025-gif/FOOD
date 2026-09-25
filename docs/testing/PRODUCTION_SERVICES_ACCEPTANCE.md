# PostgreSQL / Redis deployment acceptance

Issue #124 adds a required job to the reusable backend workflow. The existing required-ci-gate waits for the complete backend workflow, including this job.

The test requires FOODEX_DISPOSABLE_SERVICES=true, PostgreSQL and database foodex_acceptance before any destructive migration. Run only on disposable CI services; never point it at user data.

## Evidence mapping

| Release requirement | Automated exercise |
|---|---|
| Clean install / minimum seed | Real InstallerWorkflow migrations and idempotent production reference seed, no demo users/orders/products |
| Install lock / audit | Finish creates owner, version, audit and lock only after setup |
| PostgreSQL / Redis health | Installer health and actual Redis queued job processed by worker |
| Upgrade | Real ZIP, preflight, file backup, pg_dump, migrations, caches and health |
| Failed migration recovery | Nontransactional fixture changes a user then throws; real pg_restore and file rollback recover original value/version and remove new migration |
| Failure logging | Failed update history and updater.failed audit entry |

CI stores deployment-results.xml as production-services-acceptance with the run/commit. Green evidence belongs to that exact commit. This uses disposable version 99.0.0/99.0.1 fixture packages; it does not certify every previous production version or an actual production deployment.

The release owner must still supply a supported real prior-release upgrade rehearsal, infrastructure-specific deployment/backup evidence and production configuration. Do not mark the entire production release checklist complete from this job. Incomplete rollback orchestration is covered separately by #128.
