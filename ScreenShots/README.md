# FOODEX Real Product Screenshots

Issue: #175

This directory is the canonical visual/runtime evidence library for the **actual FOODEX product**.

## Non-negotiable evidence rule

Only screenshots captured from a running FOODEX build count as evidence.

Do **not** commit any of the following as product evidence:
- mockups
- Figma/design exports
- generated images
- reference artwork
- placeholder screenshots
- manually reconstructed screens

If a required route/function cannot be captured from the real product, keep #175 open and link the blocking defect.

## Required surfaces

### Customer Mobile
- `01_Mobile/B2B_Customer/` — all 13 B2B Customer rows from `docs/design-reference/SCREEN_MANIFEST.json`
- `01_Mobile/B2C_Customer/` — all 12 B2C Customer rows from the manifest

### Driver Mobile
- `01_Mobile/Driver_B2B/`
- `01_Mobile/Driver_B2C/`

Driver evidence must cover the real implemented journey, including home/deliveries and any user-visible auth, availability, shipment/task, route, proof-of-delivery, earnings, notifications, success/error/empty states that are actually reachable in the running app.

### Web Admin
- `02_Web/B2B_SuperAdmin/` — all 10 B2B Web rows from the manifest
- `02_Web/B2C_Admin/` — all 11 B2C Web rows from the manifest

Baseline total from the approved screen manifest: **46 screens**, before extra Driver/function-state evidence.

## Filename format

`NN_<screen-or-function>__<state>__<locale>.<png|webp>`

Examples:
- `02_لوحة_التحكم_الرئيسية__populated__ar.png`
- `06_إدارة_الطلبات__empty__en.png`
- `03_driver_deliveries__in_progress__ar.png`

Use stable names. Re-capture and replace evidence when the UI materially changes.

## Functional-state coverage

A single static image is not sufficient when the same screen has materially different states. Capture applicable states such as:
- populated/default
- empty
- loading
- validation/error
- success/confirmation
- permission denied
- modal/drawer/menu
- action in progress / completed
- mobile responsive state where layout materially changes

## Branding gate

Every accepted screenshot must be checked against `docs/architecture/FOODEX_BRAND_SYSTEM.md`:

- Primary green: `#158A3A`
- Dark green: `#165D2D`
- Bright green: `#27B658`
- Primary orange: `#EE731C`
- Bright orange: `#FC8F33`
- White/light-neutral primary surfaces
- Correct semantic status colors
- Arabic RTL-first
- English LTR
- FOODEX naming/identity visible where applicable
- No unapproved dominant dark-navy treatment
- No unapproved gradients
- Cards/controls follow the documented radius/spacing visual language

Any branding mismatch blocks acceptance of that screenshot and must be fixed/re-captured.

## Privacy and security

Never commit:
- credentials
- tokens
- secrets
- personal customer data
- production payment information
- private addresses/phone numbers

Use deterministic demo-safe data.

## Traceability

Every screenshot must have a row in `SCREENSHOT_MANIFEST.csv` containing:
- file path
- surface
- role/channel
- route/function
- state
- locale
- source commit/build
- real-runtime verification
- branding verification
- reviewer/notes

## Closure rule

**Issue #175 must remain open until all real screenshot files are present and every required evidence row is verified.**

Creating this folder, README, or manifest scaffold does not satisfy the issue.
