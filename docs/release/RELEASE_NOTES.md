# FOODEX 1.0.40 Release Notes

Status: synchronized production-hotfix distribution built from the merged Dashboard fixes in #585/#586. This release preserves the existing Driver minimum-version and location-enforcement production settings.

## Release identity

- Dashboard: `1.0.40`
- Customer app: `1.0.40+40`
- Driver app: `1.0.40+40`
- Customer runtime/footer identity: `1.0.40`
- Driver runtime/footer identity: `1.0.40`
- Driver diagnostics, heartbeat telemetry and version-policy current identity: `1.0.40`
- Driver diagnostics build identity: `40`

## Deployable delta since 1.0.39

### Dashboard production hotfixes
- Fix the Advertising > Coupons page HTTP 500 when a coupon has no optional start or end date by rendering nullable timestamps safely.
- Keep valid numeric Customer 360 routes unchanged while safely redirecting malformed/non-numeric placeholder references back to the Customer 360 index instead of emitting route 404 warnings.
- Add regression coverage for both production incidents captured by System Inspector.

### Validation and distribution
- Keep Dashboard, Customer and Driver release identity synchronized for reproducible distribution.
- Build a verified Dashboard Update Center package from the cumulative supported update boundary.
- Require backend tests, lint, static analysis, security audit, OpenAPI validation, MySQL/Redis install-upgrade-recovery acceptance and repository policy gates before promotion.
- Trial Distribution publishes synchronized Customer APK, Driver APK, Laravel setup/update artifacts and `BUILD_INFO.json` from the final `main` source commit.

## Explicit non-activation statement

This release does **not**:
- change production minimum-supported AppVersion rows;
- enable Driver fresh-location enforcement;
- change the existing production Driver enforcement state;
- alter Customer or Driver business behavior beyond synchronized release identity.
