# FOODEX 1.0.86 Release Notes

Status: post-1.0.79 Smart Ordering GA and WhatsApp reliability promotion.

## Release identity

- Dashboard / repository: `1.0.86`
- Customer: `1.0.86+86`
- Driver: `1.0.86+86`
- Van: `1.0.86+86`

## Included

- Complete certified Smart Ordering GA program, including menu, checkout, customer growth, WhatsApp bridge, QR attribution, analytics, merchandising and supply intelligence.
- Smart Ordering menu/category/item media visibility plus draft review-before-publish.
- Simplified Main Menu / Table / Campaign QR setup while preserving advanced controls, stable tokens and attribution.
- Production QR SVG/PDF fallback through the bundled TCPDF runtime.
- Premium Smart WhatsApp Chatbot and post-1.0.79 WhatsApp media/calling/campaign/inbox improvements.
- Meta template create/sync reliability, exhaustive pagination and first-contact consent correction.
- Canonical Dashboard SemVer repair for legacy compact installed versions such as `1.084`.

## Safety / activation

- WhatsApp/chatbot staged runtime controls remain fail-closed and separately auditable.
- Smart Ordering publication/activation remains explicit and tenant scoped.
- No release step may bypass exact-head CI or replace an immutable prior release.

## Dashboard update bundle

- Target version: `1.0.86`.
- Contains migrations: **Yes**.
- Full redeploy required: **No**.
- Package, checksum, file list and immutable GitHub Release are produced by the exact-head Release Factory and must not be hand-edited.
