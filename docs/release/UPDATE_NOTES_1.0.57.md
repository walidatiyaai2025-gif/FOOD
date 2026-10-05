# FOODEX 1.0.57 Release Notes

Status: Issue #943 release package generated and verified — existing client UI/UX normalization and Dashboard map silent refresh.

## Release identity

- Dashboard: `1.0.57`
- Customer app: `1.0.57+57`
- Driver app: `1.0.57+57`
- Customer runtime/footer identity: `1.0.57`
- Driver runtime/footer identity: `1.0.57`
- Driver diagnostics current identity: `1.0.57`
- Driver diagnostics build identity: `57`

## Included changes

- Normalize existing Customer UI typography, spacing, badges, headers, order cards, finance/statement density and contextual loading/error surfaces without creating new screens, routes or flows.
- Preserve Arabic RTL and English LTR behavior, use the existing localization resources for the More/Profile labels touched by this release, and localize Dashboard date/time display.
- Keep all product/order/invoice/finance values and currency authoritative to existing backend/store data; no business values are hardcoded.
- Keep the Dashboard Driver map mounted during background refreshes, retain the last successful markers on refresh failure, suppress overlapping refresh work, reserve stable status space, and clean up polling/network activity on page leave.
- Add regression coverage for silent map refresh, last-success preservation, duplicate-refresh suppression and disposal.

## Dashboard update bundle

- Package: `Release/Updates/FOODEX-Update.zip`
- Actions artifact: `FOODEX-Update-1.0.57`
- Target version: `1.0.57`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `ae1481abaab54131791cbb87879b963ee53bb31a01e4190ec3b181214aa640e7`
- Generated through the repository's existing `release/1.0.57-update` cumulative Dashboard update workflow.

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.
