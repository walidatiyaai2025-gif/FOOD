# FOODEX 1.0.86 — Smart Ordering & WhatsApp Reliability Release Notes

## Release identity

- Dashboard / repository release: `1.0.86`.
- Customer source identity: `1.0.86+86`.
- Driver source identity: `1.0.86+86`.
- Van source identity: `1.0.86+86`.
- Source boundary: authoritative `main` after #1560 / PR #1563, cumulative from immutable `v1.0.79`.

## Smart Ordering GA

- Promote the certified Smart Ordering program with fail-closed restaurant/site runtime, branch policy, draft/publish revisioning and tenant isolation.
- Include menu/modifier/pricing, cart/checkout/lifecycle/tracking, customer growth/loyalty, WhatsApp bridge, dynamic QR attribution, analytics, merchandising and FOODEX supply intelligence.
- Preserve the Smart Ordering GA certification workflow, rollback controls and exact-head promotion contract.

## Operator UX and QR production repair

- Add optional menu images and surface current menu/category/item media directly in the builder.
- Add a draft-only review-before-publish experience grouped as Menu → Category → Item with media, localized copy, prices and operational state.
- Simplify QR creation into Main Menu, Table and Campaign/Offer presets while retaining Advanced controls and existing stable-token/attribution behavior.
- Reuse the application-owned bundled TCPDF runtime so QR SVG/PDF generation remains available when production Composer dependencies do not expose TCPDF classes.

## WhatsApp reliability after 1.0.79

- Include WhatsApp media/calling, campaign-builder/report and inbox reliability improvements.
- Include the Premium Smart WhatsApp Chatbot with staged rollout, deterministic business actions, previews, support handoff, campaign attribution and safe intent testing.
- Include Meta template creation/synchronization fixes, exhaustive pagination/import of remote-only templates and explicit sync counts.
- Keep first-time contact consent unknown until explicit evidence is recorded; stale browser state cannot silently grant consent.

## Updater compatibility

- Normalize legacy compact installed versions such as `1.084` to canonical `1.0.84` and self-heal persisted current-version/history references.
- Reject non-canonical target/minimum version input while preserving downgrade prevention and package integrity checks.
- The 1.0.86 Dashboard package is cumulative and remains valid for a production instance currently reporting canonical 1.0.84.

## Deployment and rollback

- This release includes additive Smart Ordering and WhatsApp migrations.
- WhatsApp/chatbot and Smart Ordering activation remain explicit, audited and fail-closed; release publication does not silently enable external outbound behavior.
- Use the normal FOODEX updater recovery path for rollback and preserve audit/conversation/order data unless a separate approved data-removal procedure exists.

## Validation contract

- Repository Policy, Required CI, localization, backend tests, MySQL/Redis deployment acceptance, Store Submission Readiness, Smart Ordering GA Certification, Update Bundle validation and Dashboard visual evidence must pass on the exact promotion head.
- Merge to `main` only from that exact green head. The resulting `VERSION` change is the Release Factory publication trigger.
