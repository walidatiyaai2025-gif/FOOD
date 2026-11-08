# FOODEX 1.0.127 — Direct 125 Upgrade and Customer Service Recovery — Release Notes

## Release identity
- Dashboard and Laravel backend: `1.0.127`
- Customer, Driver and Van Android apps: `1.0.127+127`
- **Direct Dashboard upgrade:** installed `1.0.125` or `1.0.126`, subject to signed package manifest verification. An intermediate upgrade to 126 is not required.

## Included fixes and compatibility
- Customer Service assignment in Operations → Orders now opens an accessible RTL/LTR popup with multiple active team members, previously selected assignments, optional reason, explicit unassign-all and safe audit logging.
- The existing `platform_owner_registry` table is validated and preserved if a prior MySQL update created it before recording its migration.
- Missing permission `orders.customer_service_discount` is restored by migration before `BuiltInJobRoleSeeder` during cache rebuilding, preventing the production role-reconciliation failure.
- Cumulative 1.0.126 capabilities, including Customer Sign up under Sign in and Customer Groups links, are included in the Dashboard update.
- B2B VAN ONLY / B2C DRIVER ONLY and all existing customer, order, store, upload and tenant boundaries remain in force.

## Release safeguards
- The complete Setup ZIP, Update ZIP, versioned manifest, SHA-256 hashes and synchronized APKs must all be produced from the same tested protected-main commit.
- Required CI, fresh MySQL installer from the actual Setup ZIP and direct 1.0.125 → 1.0.127 upgrade using the **actual Update ZIP** are release gates.
- The upgrade test preserves the `.env` file, uploads, order, assignment, customer discount history and owner identity across missing migration-ledger and missing-permission scenarios.
- Verify GitHub Actions' OIDC build provenance against the package digest independently before uploading. The legacy 125 web updater may not enforce provenance signatures automatically.
- Production cPanel installation must have a verified backup and completed preflight; never overwrite `.env` or destroy live storage.
- Publishing an immutable GitHub Release does not prove the user's live server has upgraded. Verify version, order management, role integrity and health check on the actual dashboard after applying the ZIP.
