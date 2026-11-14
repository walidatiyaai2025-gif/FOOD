# V2 #3554 — Customer invoice supplied channel input guard

Atomic MOB-C04 child of #2733, master #2503. **Only** target `Foodex_V2`. No `main`, tags, releases, cPanel or production.

## Source finding
`CustomerInvoiceController::assertRequestedContext` cast the unvalidated `channel` request value to `string`. A supplied array such as `?channel[]=b2c` may trigger PHP's array-to-string warning and a framework error instead of the intended safe 404. It also treated explicit empty/whitespace context as absent, potentially ignoring an invalid query that shadows a valid `X-FOODEX-Customer-Domain` header. This is a source-level risk, not a confirmed production event or authorization bypass: `owned()` remains the authoritative user/customer/store invoice lookup.

## Minimal correction
After resolving the authenticated, owned invoice, independently detect whether a `channel` input key or `X-FOODEX-Customer-Domain` header is **present**. For a supplied value, require an actual string, trim and normalize case, require `b2b` or `b2c`, and require exact agreement with the owned invoice's channel. Invalid scalar/array, explicit blank, contradictory domain or mismatching channel return 404 before payload/PDF. The optional absent channel remains backward compatible. `store_id` validation and canonical invoice ownership, token auth, channel fulfillment, and store/tenant isolation are unchanged.

## Regression and surface matrix
Existing `backend/tests/Feature/V2CustomerInvoiceStoreContextTest.php` reuses realistic Retail customer/invoice fixtures (no duplicated tests/seeding) to exercise authenticated valid/missing/case-folded/header contexts and reject malformed blank, array, other-channel, unsupported values, and query shadowing on both detail and PDF; no invoice mutations. Existing foreign customer, channel/store mismatch, Arabic PDF and authorization tests remain intact.

- **Customer:** Existing authenticated invoice show/download negative-input behavior hardened. Valid request/response JSON and PDF unchanged; existing Flutter numeric/store/channel caller contract unchanged, parent #2733 tracks broader app acceptance.
- **Driver:** No Driver endpoint, Flutter model or dispatch change; B2C=DRIVER ONLY unchanged.
- **Van:** No Van endpoint, Flutter model or assignment change; B2B=VAN ONLY unchanged.
- **Dashboard:** No navigation, role, finance, screen or permission change.
- **AR/EN:** No new messages; existing safe HTTP 404 for invalid contexts, existing localized PDF remains. No RTL/LTR surface change.

## Verification requirements
Do not merge unless exact final PR head has terminal **Repository Policy**, **FOODEX V2 Isolation Gate**, **FOODEX Required CI Gate** success including Laravel feature and full backend, PHP lint/Pint/PHPStan, Composer audit/OpenAPI/AR-EN, and real MySQL/Redis install-upgrade-recovery as applicable. CI links/tested SHA and integration SHA must be included in closure comment. Manual device/staging UAT stays deferred to final master #2503, not an automatic-code-test waiver.

No API path or response schema modification: OpenAPI remains compatible.
