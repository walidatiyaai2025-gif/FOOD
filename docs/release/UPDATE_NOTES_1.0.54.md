# FOODEX 1.0.54 Release Notes

Status: final C13 synchronized distribution after umbrella #858 and integrated gate #873 / PR #917.

## Release identity

- Dashboard: `1.0.54`
- Customer app: `1.0.54+54`
- Driver app: `1.0.54+54`
- Customer runtime/footer identity: `1.0.54`
- Driver runtime/footer identity: `1.0.54`
- Driver diagnostics current identity: `1.0.54`
- Driver diagnostics build identity: `54`
- `1.0.53` was not promoted, registered or published and remains skipped.

## Included changes

- Publish the final accepted C13 Customer journey after #858 and final integrated gate #873 / PR #917, covering the canonical 13-screen Arabic/English visual and operational matrix.
- Preserve authentication-first B2B launch: Login -> Dashboard, with the four-item Shopping / My Orders / Invoices / More navigation model.
- Preserve principal Wholesale recovery from Dashboard/Shopping and Retail -> principal Wholesale switching without logout, with authoritative tenant/store/account isolation.
- Preserve authoritative purchasing power, account balance direction (عليك / لك), credit limit and available credit across Dashboard, statement, checkout and invoice surfaces.
- Preserve Customer-Service approval/rejection, Driver assignment/collection instructions, exact Customer/Driver invoice PDF authorization, and independent delivery/payment lifecycle state.
- Preserve centralized sanitized Customer/Driver/API runtime diagnostics in the Dashboard System Inspector.
- Synchronize Dashboard, Customer and Driver identities at 1.0.54 / mobile build 54 without changing production minimum-version, force-update, Driver fresh-location enforcement or Assistant activation settings.

## Dashboard update bundle

- Target version: `1.0.54`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `fe01ff66fb993d2c56abb89aafcfae9ba7f8afd5fcdf2e81cfaec5ec7ed4edee`

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.
