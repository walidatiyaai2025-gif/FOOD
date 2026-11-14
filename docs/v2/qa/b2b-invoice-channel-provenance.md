# V2 #3512 — B2B finance invoice provenance isolation

- **Source:** Customer financial document parity (#2732), B2B/B2C cross-channel HTTP isolation (#2782), master V2 acceptance #2503. Isolated integration: `Foodex_V2` only.
- **Actor / entry:** Authenticated approved B2B customer or Retail merchant with an authorized linked Wholesale B2B account; `GET /api/v1/b2b/invoices`, `/api/v1/b2b/invoices/{invoice}`, `/api/v1/b2b/invoices/{invoice}/download`. Existing `CustomerDomainResolver::b2bAccountFromRequest` + active B2B account authorization remains mandatory.
- **Verified source defect:** The pre-fix list matched only `b2b_customer_id`; detail/PDF matched this ID and optional requested `store_id`. A contradictory imported row could carry an owned B2B FK together with Retail `channel=b2c`, a populated B2C customer FK, or Retail seller-store provenance. This is source-level risk, **not a claim of observed live disclosure**.
- **Reuse → Extend:** Keep existing API routes, B2B Finance controller, financial ledger calculations, JSON keys, status filtering, pagination and PDF renderer. Extend one shared server-side query predicate for index/detail/download. No new view, action, Sidebar item, permission, mutation or navigation.
- **Authoritative guard:** Require B2B customer FK match, reject any populated B2C customer FK, accept `channel=b2b` or legacy null, and require any populated invoice store ID to join a canonical `stores.store_type_id → store_types.code=B2B`. Historical null channel/store is permitted with owned B2B FK and no conflicting B2C FK. Detail/PDF return 404 before sensitive payload/PDF/audit.
- **Data and finance safety:** No invoice/payment/account/ledger mutations, migrations, permission grants or direct storage changes. Preserve positive authorized B2B financial read and PDF behavior. Denial is server-controlled rather than UI-only. Existing B2B VAN ONLY and B2C DRIVER ONLY routing unchanged.

## Impact matrix

| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend/API | Affected | Same existing B2B finance routes, stricter SQL provenance; no new response schema |
| Dashboard | Not affected | No Dashboard or server navigation route changed |
| Customer app | Existing contract / stricter read | Wholesale finance consumes current list, detail, PDF endpoints without DTO/route changes; customer #2732 remains open for whole business-journey acceptance |
| Driver app | Not affected | No Driver API, assignment, status, finance or Flutter contract touched |
| Van app | Not affected | No Van API, settlement, offline or Flutter contract touched |

## Automated acceptance

`backend/tests/Feature/V2B2bInvoiceChannelProvenanceTest.php` exercises authenticated RefreshDatabase HTTP boundaries: valid B2B seller invoice, historical null channel/store invoice, forged B2C channel, mismatched Retail seller, dual-domain B2C FK, unrelated approved buyer; both `ar`/`en` modes; index filtering, detail and PDF 404, no unauthorized financial audit or data mutation. Existing B2b finance and Customer API tests must remain green. Exact final-head Repository Policy, FOODEX V2 Isolation and full Required CI (Laravel, PHP lint/Pint/PHPStan, security, AR/EN, MySQL/Redis as applicable) must be terminal SUCCESS before merge. No actual device/staging UAT or cPanel deployment is claimed; final program UAT remains #2503.

## Closure evidence

Record final source SHA, Required CI workflow/job IDs and merge SHA on #3512 after verified green CI only; leave parents #2732, #2782 and #2503 open. No `main` integration, tag, production or release authorization.
