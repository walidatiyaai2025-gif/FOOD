# V2 #3504 — Customer invoice context input guard (MOB-C04)

Parent: #2733 / #2503. Integration branch: `Foodex_V2` ONLY. No production or main promotion.

## Static source finding and minimal correction

`CustomerInvoiceController::assertRequestedContext` previously checked store equality only for positive `is_numeric()` values. A supplied empty, zero, negative, malformed or unsupported integer value skipped the comparison. The separate `owned($user)` customer filter remained enforced; this was **not** demonstrated to leak another customer's invoices. The fix requires every **supplied** store context to be a nonempty positive decimal, with overflow-safe native integer normalization through the existing `DisplayNumber::referenceId` helper, then exact invoice store equality. Null/absent optional store context retains API compatibility. The existing channel check and customer-ownership query are unchanged.

## API / authorization / app impact

- GET `/api/v1/invoices/{invoice}`: authenticated Customer invoice detail; malformed store context now responds 404, valid owned matching store or absent context returns the existing JSON.
- GET `/api/v1/invoices/{invoice}/download`: same record scope for PDF (including locale=ar/en). Malformed store context now responds 404 before generating PDF; valid PDF response remains unchanged.
- Customer: the existing mobile app uses numeric store IDs and retains existing schema; invalid context rejection is a Customer API hardening effect linked to #2733. No Dart code/response schema changes. No navigation/screen added.
- Driver: no Driver endpoint, app model, navigation or dispatch change. B2C=DRIVER ONLY is preserved.
- Van: no Van endpoint, app model, navigation or dispatch change. B2B=VAN ONLY is preserved.
- Dashboard: no UI/route/role changes. No tenant/role privilege expansion, no persistent data writes.

## Automated regression evidence / acceptance

`backend/tests/Feature/V2CustomerInvoiceStoreContextTest.php` covers owned invoice detail + Arabic PDF success, omitted optional context, matching store header, malformed empty/blank/zero/negative/noninteger/exponent/overflow and array contexts rejected on detail and PDF, mismatched store, invalid-query shadow of a valid header, wrong channel, foreign customer and no invoice mutation. Full Laravel / security / API / localization CI and exact final head status **must be recorded after GitHub Actions runs**; this file intentionally makes no unverified PASS claim.

Manual/real device/staging signoff remains at final #2503 acceptance only; #3504 is coding-complete solely if its issue-scoped PR is merged with required exact-final-head gates green. No artifacts or deployment involved.
