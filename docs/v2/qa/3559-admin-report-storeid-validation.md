# FOODEX V2 #3559 — strict Admin reports store context

Isolation: Foodex_V2 only; no main, tags, releases, cPanel or production. Parent #2503 and cross-store #2782 remain open. Builds on completed #3542 localized 422 errors, without changing that contract.

## Source finding and boundary

Authenticated GET /api/v1/admin/reports/dashboard accepts optional store_id only for Super Admin and requires it for store-scoped reporters. AdminReportController::storeScope previously called coercive Request::integer on unvalidated input; for Super Admin, Request::filled could treat an explicitly malformed/empty supplied context as absent, selecting scope=platform. Static input ambiguity, not an observed production exploit.

## Behavior

- Only an omitted store_id allows authorized Super Admin global reports.
- Supplied input must be a positive canonical decimal integer within PHP integer range. Arrays, malformed scalar, leading zero, scientific notation, overflow, blank, zero and negative return field-scoped localized HTTP 422 errors.store_id using existing reports.store_required translations.
- Preserve reports.view Gate, explicit store role assignment, foreign-store 403, authorization order, report SQL aggregations, Super Admin valid store filter and the existing response shape.
- No new UI/navigation/permission/translation/route/schema/migration/persistent write. OpenAPI error envelope shape and status unchanged.

## Regression and surface matrix

The existing RefreshDatabase V2AdminReportStoreScopeLocaleTest adds nine malformed formats for each of authenticated Arabic store-scoped reporters and English Super Admin, plus own-store 200, foreign-store 403 and no-store privileged platform 200. Retains the older #3542 tests for explicit and fallback locale. Verify Pint/PHPStan, Laravel full suite, security, AR/EN and real MySQL/Redis exact-head Required CI.

Backend reporting API validation affected. Dashboard existing Reporting route and AR/EN errors unchanged, no Blade page edits. Customer routes/API/Flutter unaffected; Driver API/dispatch/Flutter unaffected; Van API/assignment/Flutter unaffected. Wholesale B2B=VAN ONLY and Retail B2C=DRIVER ONLY preserved.

Close only this atomic CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED after exact-head green CI and verified issue PR merge into Foodex_V2; parent #2503 retains broader staging/manual/ERP acceptance. No production claims.
