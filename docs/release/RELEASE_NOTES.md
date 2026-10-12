# FOODEX 1.0.74 Release Notes

Release promotion of the completed Customer VNext and Mobile Smart Store Analytics integrated main lineage.

## Release identity

- Dashboard / repository release: `1.0.74`
- Customer source identity: `1.0.74+74`
- Driver source identity: `1.0.74+74`
- Van source identity: `1.0.74+74`
- Dashboard Update ZIP: independently deployable over existing FOODEX 1.0.73
- Android artifacts: Customer, Driver and Van APKs built from the same release commit
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Complete the Customer VNext umbrella #1323 and all child lanes #1324–#1334, including unified mobile UX, realistic sanitized data, protected reset/reseed, production preflight/Launch controls and the independent #1333 certification merge.
- Include Mobile Smart Store Analytics #1354–#1359 with authoritative EGP analytics, Dashboard-managed store logo, compact phone UI, insight badge/pulse lifecycle, accessibility safeguards, explicit Retail context isolation and final integrated convergence.
- Preserve the post-certification security/runtime/visual gates merged through PR #1366 and main lineage after #1359.
- Keep production Launch and destructive reset fail-closed behind owner data, approved high-resolution assets, backup and green preflight requirements.

## Validation contract

- Repository Policy, Required CI, release validation, localization, backend/MySQL, Customer/mobile validation, Update Bundle validation and fresh-install acceptance must pass on the exact release lineage.
- The generated `FOODEX-Update.zip` must target `1.0.74`, remain cumulative from the supported updater boundary and report no full redeploy requirement.
- The Release Factory must build the Dashboard update and Customer/Driver/Van APKs from one synchronized source commit.
- Generated Dashboard package: 919 files; migrations included; no full redeploy required; SHA-256 `5c8a4f63f133ff4cd0e8f333adf3676f61d31ab217417cc84b7fae3ba1d66c44`.
