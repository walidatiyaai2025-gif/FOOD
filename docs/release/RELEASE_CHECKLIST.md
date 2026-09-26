# FOODEX Production Release Checklist

This checklist is the release evidence required by PC-19. A release is not production-ready until every applicable item is backed by a passing CI result or recorded deployment evidence.

Evidence convention:
- `[x]` means the repository/CI requirement has concrete merged evidence.
- `[ ]` means production/staging-specific evidence or release-owner input is still required.
- Validation-only mobile artifacts do not count as signed/store-ready production binaries.

## Platform and data

- [x] Clean install completes through the First-Run Installer and writes its install lock only after successful completion. Evidence: #124 / PR #130 production-services acceptance on disposable PostgreSQL/Redis.
- [ ] Upgrade from the previous supported release completes through Update Center. CI validates a real updater package fixture, but the exact previous supported production release package is still required for rehearsal.
- [ ] Database migrations complete on a production-like copy and rollback safety is verified before maintenance mode exits. Disposable PostgreSQL recovery is green; target staging/production-copy evidence remains required.
- [x] Minimum production bootstrap/reference data is present without demo credentials or sample customer data. Evidence: #124 production-services acceptance.

## Update safety and observability

- [x] Update package integrity/compatibility preflight passes in automated updater acceptance.
- [x] File and database backup completes before release extraction/migration in disposable PostgreSQL acceptance.
- [x] Post-update health check passes for application, database and writable storage in automated acceptance.
- [x] Rollback restores files and database for the tested failure path; #128 keeps maintenance enabled when rollback itself cannot complete safely.
- [x] Correlation IDs and structured server error logs are covered by merged production-readiness/backend validation without exposing sensitive details.

## API, authorization and security

- [x] OpenAPI contract tests pass and the published contract matches implemented v1 routes in required backend validation.
- [x] No unresolved P0/P1 authorization, store-isolation, B2B/B2C channel-isolation or security defects remain in the current approved execution queue after RC-03 finalization.
- [x] Critical mutations remain permission checked and audited by the merged authorization/action-wiring suites.
- [ ] Production secrets are supplied through environment configuration and are not committed to the repository. Repository policy prevents committing credentials; actual production values remain an external release input.

## Mobile release validation

- [x] Customer App Android release build/validation passes. #133/#138 verify release build, checksum, INTERNET permission and FOODEX native label.
- [x] Customer App iOS no-codesign release validation passes; #161 verifies the FOODEX native display name. Production signing remains external.
- [x] Driver App Android release build/validation passes. #133/#138 verify release build, checksum, INTERNET permission and FOODEX Driver native label.
- [x] Driver App iOS no-codesign release validation passes; #161 verifies the FOODEX Driver native display name. Production signing remains external.
- [x] Mobile Version Policy returns supported/minimum versions and update behavior from the backend rather than embedded business rules.
- [x] Arabic RTL and English LTR acceptance journeys are covered by merged cross-surface/mobile tests.

## Final release gate

- [x] Repository Policy, backend validation, Customer Flutter validation, Driver Flutter validation and required-ci-gate are green on release-evidence PR #172.
- [x] PC-18 cross-surface E2E acceptance is merged and green.
- [ ] VERSION and release notes identify the exact release being promoted.
- [ ] Deployment owner records the production/staging backup identifier, health-check result and rollback decision point.
- [ ] Production HTTPS API endpoint, Customer/Driver application and bundle IDs, Android signing and Apple signing inputs are supplied securely and final install/device acceptance passes.

## External closure inputs

Release/deployment closure is intentionally blocked rather than guessed until these concrete inputs exist:

1. Exact previous supported FOODEX release package/version for the real upgrade rehearsal.
2. Target staging/production environment and production HTTPS API URL.
3. Approved Customer Android applicationId and iOS bundle ID.
4. Approved Driver Android applicationId and iOS bundle ID.
5. Android production signing configuration and Apple signing team/certificates/profiles.
6. Production/staging backup identifier plus post-deploy health-check and rollback decision evidence.
7. Final physical-device installation/runtime acceptance for both mobile binaries.

See `docs/testing/PRODUCTION_SERVICES_ACCEPTANCE.md` and `docs/testing/MOBILE_RELEASE_VALIDATION.md` for the exact boundary between automated validation and production evidence.
