# FOODEX Production Release Checklist

This checklist is the release evidence required by PC-19. A release is not production-ready until every applicable item is backed by a passing CI result or recorded deployment evidence.

## Platform and data

- [ ] Clean install completes through the First-Run Installer and writes its install lock only after successful completion.
- [ ] Upgrade from the previous supported release completes through Update Center.
- [ ] Database migrations complete on a production-like copy and rollback safety is verified before maintenance mode exits.
- [ ] Minimum production bootstrap/reference data is present without demo credentials or sample customer data.

## Update safety and observability

- [ ] Update package integrity/compatibility preflight passes.
- [ ] File and database Backup completes before release extraction/migration.
- [ ] Post-update Health Check passes for application, database and writable storage.
- [ ] Rollback restores files and database when the update pipeline reports a safe rollback condition.
- [ ] Correlation IDs and structured server error logs are available for failed requests without leaking sensitive details.

## API, authorization and security

- [ ] OpenAPI contract tests pass and the published contract matches implemented v1 routes.
- [ ] No unresolved P0/P1 authorization, store-isolation, B2B/B2C channel-isolation or security defects remain.
- [ ] Critical mutations remain permission checked and audited.
- [ ] Production secrets are supplied through environment configuration and are not committed to the repository.

## Mobile release validation

- [ ] Customer App Android release build/validation passes.
- [ ] Customer App iOS no-codesign release validation passes; signing is performed only with release credentials outside CI when required.
- [ ] Driver App Android release build/validation passes.
- [ ] Driver App iOS no-codesign release validation passes; signing is performed only with release credentials outside CI when required.
- [ ] Mobile Version Policy returns supported/minimum versions and update behavior from the backend rather than embedded business rules.
- [ ] Arabic RTL and English LTR acceptance journeys remain green.

## Final release gate

- [ ] Repository Policy, backend validation, Customer Flutter validation, Driver Flutter validation and required-ci-gate are green on the release PR.
- [ ] PC-18 cross-surface E2E acceptance remains merged and green.
- [ ] VERSION and release notes identify the release being promoted.
- [ ] Deployment owner records the production backup identifier, health-check result and rollback decision point.
