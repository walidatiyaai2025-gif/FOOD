# FOODEX 1.0.75 Release Notes

Synchronized promotion of the authoritative current main lineage to FOODEX 1.0.75.

## Release identity

- Dashboard / repository release: `1.0.75`
- Customer source identity: `1.0.75+75`
- Driver source identity: `1.0.75+75`
- Van source identity: `1.0.75+75`
- Dashboard Update ZIP: direct update over FOODEX 1.0.74
- Android artifacts: Customer, Driver and Van APKs built from the same release lineage
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Carry forward the complete Customer VNext scope and final certification merged before 1.0.74.
- Carry forward Mobile Smart Store Analytics, including authoritative Retail analytics in EGP, live Dashboard-managed store branding, compact mobile analytics, unread insight badge/pulse lifecycle and Retail/Wholesale context isolation.
- Preserve production preflight, protected reset/reseed and exact-head release validation.
- Rebuild synchronized release artifacts at version 1.0.75 without weakening any launch, security, migration or endpoint provenance gate.

## Validation contract

- Repository Policy, Required CI, localization, backend/MySQL, Customer/mobile validation, runtime evidence, update-bundle validation and fresh-install checks must pass on the exact release lineage.
- The generated `FOODEX-Update.zip` must target `1.0.75`, support direct update from `1.0.74`, and report no full redeploy requirement unless the generator explicitly detects one.
- Release Factory must publish Dashboard/Setup assets plus Customer, Driver and Van Android artifacts from the synchronized release.
