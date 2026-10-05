# FOODEX 1.0.55 Release Notes

Status: owner-approved post-#921 synchronized Dashboard/Customer/Driver distribution.

## Release identity

- Dashboard: `1.0.55`
- Customer app: `1.0.55+55`
- Driver app: `1.0.55+55`
- Customer runtime/footer identity: `1.0.55`
- Driver runtime/footer identity: `1.0.55`
- Driver diagnostics current identity: `1.0.55`
- Driver diagnostics build identity: `55`

## Included changes

- Promote the owner-approved Customer visual and functional convergence from #920 / PR #921: FOODEX reference login, single-screen Business Dashboard, compact Top Products, discoverable Wholesale Business Dashboard shortcut, redesigned More surface, and refreshed Orders/footer composition.
- Keep all business values, permissions, account/store entitlement, finance metrics, orders and navigation authoritative to the existing backend/API/session state with no hardcoded production business data.
- Preserve Arabic/English RTL/LTR behavior, Remember Me and biometric sign-in, exact Wholesale store context, Retail/Wholesale isolation, and the validated five-destination Customer navigation contract.
- Preserve the existing production activation policy while synchronizing Dashboard, Customer and Driver release identities at 1.0.55 / mobile build 55.

## Dashboard update bundle

- Target version: `1.0.55`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `d77dc785d8b3bd8b10919dc602642b24fd4233ecf03ab9157ccf15ce9c434748`

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.
