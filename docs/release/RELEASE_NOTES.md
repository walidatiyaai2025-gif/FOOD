# FOODEX 1.0.0 Release Notes

Status: release candidate identity for the completed repository baseline. Production promotion remains blocked only by the external deployment/signing evidence tracked in #124 and #125.

## Source state

- Release version: `1.0.0`
- Customer app: `1.0.0+1`
- Driver app: `1.0.0+1`
- Runtime evidence baseline merged in #177 at `3261d9b46f34358ee653ef588022c7820d46734f`.
- The final promoted source SHA is the main commit containing this release identity after #178 is merged.

## Product scope in 1.0.0

### Web administration
- B2B and B2C management surfaces are implemented with FOODEX branding, Arabic/English localization and store/channel-aware authorization.
- Product, inventory, order, customer, promotion, driver/delivery, pricing approval, reports, settings and governance flows are wired to working backend data.
- Critical mutations remain permission-checked and audited.

### Customer mobile
- One Customer binary supports the approved B2B and B2C journeys without duplicating server-owned business rules.
- B2B includes dashboard, purchase reports, top products, invoices, account statement, orders, catalog, product detail, cart and profile.
- B2C includes store selection, catalog/offers, product detail, cart, checkout authentication, address/payment, order tracking and profile/favorites.
- Arabic RTL and English LTR are supported.

### Driver mobile
- One Driver binary supports B2B/B2C channel behavior.
- Login, home, deliveries, delivery actions and empty/offline states are implemented and evidenced.
- Arabic RTL and English LTR are supported.

## Release and quality evidence
- Required repository policy and required-ci-gate are green on the completed product baseline.
- Backend validation covers tests, lint, static analysis, security audit and OpenAPI contract checks.
- PostgreSQL/Redis deployment acceptance covers clean install, updater fixture, backup/restore and recovery behavior on disposable services.
- Customer and Driver Android release-mode plus iOS no-codesign validation artifacts are reproducible.
- Screenshot evidence contains 110 real runtime PNGs covering all 46 approved baseline screens plus Driver states.
- Screenshot audit reports zero canonical FOODEX palette failures and zero role/surface brand-anchor failures.

## External production blockers

The following are intentionally not fabricated and must be supplied before production/store-ready closure:

1. Exact previous supported FOODEX release package/version for the real upgrade rehearsal.
2. Target staging/production environment and production HTTPS API URL.
3. Approved Customer Android applicationId and iOS bundle ID.
4. Approved Driver Android applicationId and iOS bundle ID.
5. Android production signing configuration and Apple signing team/certificates/profiles.
6. Production/staging backup identifier, post-deploy health check and rollback decision evidence.
7. Final physical-device installation/runtime acceptance for both mobile binaries.

These blockers remain owned by #124 and #125. This document does not mark them complete.
