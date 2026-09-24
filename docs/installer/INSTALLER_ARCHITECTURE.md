# First-run Installer Architecture

Entry point: `/install` only while no install lock exists. The installer is a Laravel web flow and remains backend-authoritative; there is no public installer API contract.

## Required sequence

1. Welcome
2. Server Requirements
3. File/Folder Permissions
4. Database Configuration
5. Test Database Connection
6. Platform Information
7. Create Super Admin
8. Storage Configuration
9. Cache/Queue Configuration
10. Mail Configuration
11. Run Migrations
12. Seed Required Core Data
13. Generate Application Keys
14. Health Check
15. Finish

Steps are sequential. Progress is persisted under protected application storage so application-key rotation at step 13 cannot lose the wizard state. The installer does not depend on encrypted cookies or sessions, so it can boot before `APP_KEY` exists. POST actions require a random installer progress token stored only in protected application storage. Super Admin plaintext credentials are never persisted: step 7 stores only the password hash until the schema and core roles are available.

## Secure configuration

The installer may write only an explicit allow-list of `.env` keys. Database, Redis and SMTP secrets are never rendered back into the form, logged, committed to Git, or stored in installer progress. Configuration writes use a temporary file followed by an atomic rename.

Production database configuration is PostgreSQL. The database connection is explicitly tested before migrations. Migrations run with `--force`, and seeding uses the deterministic `DatabaseSeeder`/`CoreReferenceSeeder` path.

## Health and finish

The health step verifies:

- database connectivity;
- writable protected storage;
- configured cache availability;
- supported queue connectivity/configuration.

A successful finish is idempotent enough to recover from an interrupted final request: it creates or updates the prepared Super Admin, attaches `SUPER_ADMIN`, persists the current `VERSION` in `system_versions`, hardens `APP_ENV`/`APP_DEBUG`, emits `installer.completed` through the shared audit foundation, and only then writes `storage/app/system/installed.lock`.

Once the lock exists, all `/install` GET/POST routes return 404. Installer progress is removed and the browser is redirected to `/admin`, where the normal Management Dashboard authentication boundary remains authoritative.
