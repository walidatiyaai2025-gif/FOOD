# V2 #2791 / T037R05 — updater migration backup declaration guard

## Scope and authorized entry point

- Source: FOODEX Unified RBAC/ERP discussion T037R05, parent #2503.
- Existing navigation: Settings → System → System Updates (ADMIN_SETTINGS), `admin.system-update.index` and `admin.system-update.store`; reuse the existing SystemUpdateController, UpdateManager, UpdatePackageValidator and LaravelUpdateRuntime.
- Authorized actor: authenticated Super Admin granted `system.update`. Server-side Gate authorization remains unchanged; no new permission, route, financial role or store override.
- Storage/tenant: a deployment-wide privileged update only; no B2B/B2C user or store is made an updater. No new read/write surface.
- Critical invariant: a valid update ZIP that contains `backend/database/migrations/*.php` may **never** be accepted as `contains_migrations=false`, whether a caller uses the HTTP upload endpoint or directly invokes the update manager with a manifest. Without this guard, the manager could omit both a DB backup and migration execution.
- Implementation: after package SHA-256 verification, the validator inspects actual ZIP entry names when the caller claims `contains_migrations=false`, normalizing Windows-style separators. It rejects such an inconsistent package before the runtime preflight, file backup, DB backup, maintenance mode or live-file mutations. Existing unparseable-archive rejection remains in LaravelUpdateRuntime::preflight. Legitimate migration ZIPs declaring `true` retain existing verified-backup and migration orchestration.

## Regression coverage

`backend/tests/Unit/UpdatePackageValidatorTest.php` covers:
1. ZIP with Unix-style migration path and a false declaration is rejected.
2. ZIP with backslash-normalized migration path and a false declaration is rejected.
3. ZIP with migration path and a true declaration passes the manifest validator.
4. Existing SHA-256 tamper detection, semver compatibility and downgrade tests are preserved.

Run `cd backend && php artisan test --filter=UpdatePackageValidatorTest` and the Required CI gate on the exact issue PR head; no claim of pass until GitHub reports it. PHP ZIP extension is required for real server updates. The test cases skip if ZIP is unavailable on a local runner, while CI must provide ZIP to exercise the guard.

## Mobile/API and release impact

- Customer: no endpoint, payload, role, app, or price contract changed.
- Driver: no assignment, wallet, status, API or app contract changed.
- Van: no trip, B2B dispatch, route, inventory, API or app contract changed.
- No new screens/localized strings or public-facing user interaction; existing Dashboard AR/EN and RTL/LTR UI unchanged.
- B2B VAN ONLY / B2C DRIVER ONLY invariant unchanged.
- No release or deployment is authorized from this V2 PR.

## Explicitly outstanding acceptance

This is a bounded hardening of the current updater, **not** the full T037R05 acceptance. The existing Update ZIP flow still requires an independently verified cryptographic signing/trusted-key contract, exact Setup/Update packages, fresh-install/upgrade with data preservation, full recovery and signed-artifact provenance under #2746/#2846, plus final V2 audit #2503. SHA-256 alone cannot prove publisher authenticity; do not label this release production-ready or close #2791 until those requirements are proven. Final owner browser/UAT acceptance remains deferred to the V2 acceptance gate.
