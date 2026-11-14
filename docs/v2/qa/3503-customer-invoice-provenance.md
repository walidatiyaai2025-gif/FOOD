# V2 #3503 — Customer invoice provenance / Retail store authorization

- Source: MOB-C04 #2733, route-isolation P031 #2782, master #2503.
- Integration target: `Foodex_V2` **only**. No `main`, release, tag, cPanel or production.
- Surface: existing authenticated Customer `GET /api/v1/invoices`, `GET /api/v1/invoices/{invoice}`, and `GET /api/v1/invoices/{invoice}/download`. Reuse existing routes and response/PDF contract; no new navigation or UI.

## Confirmed source gap and remedy

`CustomerInvoiceController::owned()` previously OR-matched platform, B2B, B2C and legacy customer identifiers. One owned legacy customer FK could override a contradictory non-null platform/domain FK on an imported or inconsistent invoice. The read query did not require the B2C customer home store to match the invoice's populated store and did not reject contradictory populated B2B/B2C fields on a known invoice channel. This is an authorization-in-depth defect, **not** evidence that live users exploited it.

The server-side read query now requires:
1. At least one actual identifier belonging to the requesting user, as before.
2. **Every populated identity reference** on the same invoice belongs to the requesting user; a null historical reference remains valid. Matching a legacy/other column cannot override a foreign populated identifier.
3. A populated B2C customer reference on a populated invoice store matches the authoritative `b2c_customers.store_id`; missing historical store remains supported.
4. For explicit `b2b` channel, the B2C reference is null; for explicit `b2c` channel, B2B reference is null. Historical null channel is allowed.

All filters compose into a single Eloquent SQL query. Index, show and PDF share `owned()`; no unbounded in-memory filtering, data modification, file access, financial mutation or new endpoint.

## Authorization and app-impact matrix

| Surface | Impact | Verification |
| --- | --- | --- |
| Backend Customer API | Read-side authorization strengthened on list/detail/PDF | New real authenticated RefreshDatabase HTTP regression; foreign IDs 404 and excluded from index |
| Dashboard | No route, role, permission, navigation or Blade change | No Dashboard file touched |
| Customer App | Existing invoice list/detail/download paths keep request/response schema. Inconsistent/foreign records are hidden/404; legitimate records remain accessible | Existing Customer invoice Flutter contract unchanged; no Flutter code/translation change |
| Driver App | No driver execution, wallet or assignment API change | Existing Driver-only B2C fulfillment unaffected |
| Van App | No Van visits, inventory or dispatch API change | Existing Van-only B2B fulfillment unaffected |

No new AR/EN label is introduced. Test calls the existing denial path with `locale=ar` and `locale=en`; 404 does not render the foreign PDF. No actual-account data or secrets are used.

## Test and acceptance records

- New `backend/tests/Feature/V2CustomerInvoiceProvenanceGuardTest.php`: legitimate B2C and null-reference legacy invoices visible; contradictory platform/B2C/legacy owners rejected from list/detail/PDF in AR/EN; wrong Retail store and B2B channel denied without deleting invoices.
- Existing `CheckoutDomainTest::test_customer_invoice_is_snapshot_owned_and_downloads_in_arabic_and_english` covers real legitimate Arabic/English PDF output; preserve its success.
- This issue is an atomic security fix; it does **not** certify all #2733 invoices/notifications/deep links, all #2782 routes, or V2 final UAT. Parents remain OPEN.
- Exact-final-head Repository Policy, V2 Isolation, Required CI (Laravel, MySQL/Redis acceptance, PHP lint/Pint/PHPStan/security, AR/EN) must be terminal SUCCESS before merging to `Foodex_V2`, then close only #3503 with tested/integration SHAs and run URLs.
