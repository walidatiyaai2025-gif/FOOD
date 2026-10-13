# FOODEX 1.0.76 Release Notes

Synchronized promotion of the frozen final Customer lineage from #1376 to FOODEX 1.0.76.

## Release identity

- Dashboard / repository release: `1.0.76`
- Customer source identity: `1.0.76+76`
- Driver source identity: `1.0.76+76`
- Van source identity: `1.0.76+76`
- Dashboard Update ZIP: direct update over FOODEX 1.0.75
- Android artifacts: Customer, Driver and Van APKs built from the same release lineage
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Promote the approved final Customer runtime while preserving the frozen Customer feature scope.
- Carry App Review and authenticated Smart Store Analytics into the synchronized release lineage.
- Include the integrated online-first networking, single biometric login, persistent Home navigation, three-column grids and latest compact Customer surfaces already merged on main.
- Preserve production preflight, protected reset/reseed and exact-head release validation.

## Validation contract

- Repository Policy, Required CI, localization, backend/MySQL, Customer/mobile validation, runtime evidence, update-bundle validation and fresh-install checks must pass on the exact release lineage.
- The generated `FOODEX-Update.zip` must target `1.0.76`, support direct update from `1.0.75`, and report no full redeploy requirement unless the generator explicitly detects one.
- Release Factory must publish Dashboard/Setup assets plus Customer, Driver and Van Android artifacts from the synchronized release.
