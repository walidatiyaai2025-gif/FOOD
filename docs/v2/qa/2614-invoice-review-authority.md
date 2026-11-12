# V2 P284 — Invoice review / secure provenance authority

- **Issue:** #2614 (P284), parent #2503. **Target:** `Foodex_V2` only, never main or production.
- **Placement:** Reuse the existing authorized per-record invoice review (`admin.invoices.show`) linked by the canonical Operations / Orders and retail Finance grids. No new direct-URL-only page and no duplicate Sidebar destination. Existing invoice detail is responsive (320px–desktop), bilingual AR/EN, with print/PDF, totals, correction reason, revision history and payment settlement controls.
- **Entry point / navigation:** Dashboard `operations` group `order_operations`, real invoice action on an exact order, and the existing store-scoped Retail Finance invoice link. `SIDEBAR → CONTEXT_ACTION`; back path returns via existing Dashboard navigation (the invoice view also has browser back affordance).
- **User goal:** review an immutable financial document, download PDF, and if authorized correct it via recorded void/reissue, not modify historic invoice totals in place.
- **Field classification:** correction reason = legitimate free text; store/channel/order/customer references = server-derived technical fields, never business-user-entered IDs.
- **Permission:** `finance.view` for show/PDF, `finance.manage` for corrections/settlement. Existing controller uses 404 for unauthorized record IDs to avoid account/store enumeration; 404 is a deliberate privacy-safe denial, not a Sidebar grant. Read access must not imply write authority.
- **Data scope:** invoice store+channel, cross-validated against its referenced order if present; otherwise resolve channel from authoritative `stores → store_types` when legacy invoices have a valid store but no channel. Reject unknown store, contradictory channel, broken order reference, or orphan with no trusted tenant context. Never replace missing scope with global `finance.view` or Super Admin role.
- **Module/entitlement/readiness:** per-order invoice review is a core Finance/Orders capability, not a second ERP Finance workbench. The existing `admin.erp.finance.index` module is gated separately by its own enterprise readiness/entitlements; this PR does not switch modules on or change existing financial mutation flows. If future ERP invoice-review actions are introduced, they require their own true readiness gate.
- **Authorization matrix:** B2B_ADMIN may view valid B2B invoices in authorized Wholesale context; B2C_STORE_ADMIN may view own Retail invoice only; other Retail store and Wholesale invoice must deny, and explicit `finance.manage` DENY blocks correction while retaining authorized `finance.view`. Inactive/roleless actors are covered by the existing Dashboard auth and navigation suites.
- **Localization:** existing invoice Blade uses `lang` and `dir` according to selected AR/EN locale, bilingual labels, formatted monetary amounts and localized PDF parameter. The regression includes real rendered locale and direction checks.
- **Responsive/a11y:** existing invoice view uses `foodex-admin-layout`, shared mobile header/off-canvas Sidebar, viewport-scaled grids and accessible 44px payment-dialog controls; no UI markup modified. Full runtime keyboard/a11y/device acceptance is deferred to #2750 per updated owner policy, not asserted as executed here.

## Implemented

`backend/app/Http/Controllers/Admin/InvoiceController.php`:

1. No fallback granting any broadly privileged finance actor read/write to a tenant-unattributable invoice.
2. Cross-check order and invoice store/channel when an order is referenced; a dangling or conflicting order fails closed.
3. Safely infer missing channel on legacy store-only invoice from the canonical store type; never actor role, invoice snapshot or client request.
4. Ensure the store exists and its recorded type matches the invoice/order channel before invoking `OperationalTenantScope::allowedStoreIds`.

`backend/tests/Feature/V2InvoiceReviewAuthorityTest.php`: six full HTTP Feature regressions for B2B AR/EN invoice detail, B2C own/foreign-store+channel, no-scope including Super Admin read/download/reissue, valid legacy store-only recovery, invalid/mismatched context, and finance-manage DENY with unmodified invoice.

## Cross-surface impact

| Surface | Effect | Evidence |
|---|---|---|
| Backend (Admin web) | Changed invoice provenance authorization; contracts become more restrictive for genuinely unscoped financial records. | HTTP Feature test (new) plus existing `DashboardOrderManagementTest::test_retail_invoice_dashboard_is_store_scoped_and_supports_controlled_reissue`. |
| Dashboard | Uses existing invoice view and scoped Operations navigation; no markup, route or translation files changed. | Real Blade AR/EN HTTP test and canonical screen contracts. |
| Customer Flutter | No API/mobile code or customer invoice API route touched. Existing invoice representation and amounts unchanged. | Diff touches only Admin InvoiceController, Backend Feature test and QA doc; `/api/v1` unchanged. |
| Driver Flutter | No Driver status, settlement API, navigation or application code changed. | Diff excludes Driver app/API. B2C=DRIVER remains. |
| Van Flutter | No Van inventory, B2B dispatch, collection API or application code changed. | Diff excludes Van app/API. B2B=VAN remains. |

A mobile impact issue is only needed if later code affects mobile APIs; this server-only admin-read authorization adjustment does not.

## Verification, release fence

- `php -l backend/app/Http/Controllers/Admin/InvoiceController.php` and `php -l backend/tests/Feature/V2InvoiceReviewAuthorityTest.php` must pass.
- `cd backend && vendor/bin/pint --test app/Http/Controllers/Admin/InvoiceController.php tests/Feature/V2InvoiceReviewAuthorityTest.php`
- `cd backend && php artisan test --filter=V2InvoiceReviewAuthorityTest`
- `cd backend && php artisan test --filter=DashboardOrderManagementTest`
- Exact PR-head Repository Policy, FOODEX V2 Isolation Gate, full Laravel/backend, AR/EN, security/PHPStan, MySQL/Redis and Required CI terminal child job must be verified on the **last commit** before merge.
- Do not claim local or live-runtime checks passed without actual results. One branch, one PR, no production tags/artifacts or `main` integration. If complete and CI all green, merge only to `Foodex_V2` and close technical leaf with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503. 
