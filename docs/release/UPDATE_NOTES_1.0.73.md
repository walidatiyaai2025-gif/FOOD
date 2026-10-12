# FOODEX 1.0.73 Release Notes

Release promotion tracked by Issue #1308 / PR #1309.

## Release identity

- Dashboard / repository release: `1.0.73`
- Customer source identity: `1.0.73+73`
- Driver source identity: `1.0.73+73`
- Van source identity: `1.0.73+73`
- Dashboard Update ZIP: independently deployable over existing FOODEX 1.0.72
- Android publication / installed-APK policy: APKs are built as synchronized release artifacts; deployment to devices remains explicit
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Promote the authoritative post-1.0.72 main lineage into FOODEX 1.0.73.
- Apply the Dashboard Mobile Web charter across authenticated admin pages from PRs #1298 and #1303, with responsive navigation, record-card tables, forms, dialogs, pagination and 320-430px AR/EN acceptance.
- Hide Inventory Management and System Settings / Lookups from the shared B2B sidebar through PR #1305 while keeping the underlying authorized routes available.
- Remove Softarkan branding from customer report PDFs through PR #1307 while preserving page numbering and report structure.
- Carry forward Release Factory reliability fixes from PRs #1290, #1295 and #1299.
- Synchronize Customer, Driver and Van source identities at 1.0.73+73 from the same release lineage.

## Validation contract

- Required CI, Repository Policy, Store Readiness and Update Bundle validation must pass on the exact release head.
- The generated `FOODEX-Update.zip` must target `1.0.73`, remain cumulative from the supported updater boundary and report no full redeploy requirement.
- Generated Dashboard package SHA-256: `3a7d74ad10d5328b225205852fa1848482ce6f825ca922607781cf118adcbf9f` (registered before merge).
- The main VERSION promotion triggers the canonical Release Factory to build Customer, Driver and Van Android artifacts plus Dashboard/Setup assets from the same source commit.
