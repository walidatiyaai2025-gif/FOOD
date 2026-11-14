# V2 #3542 — scoped Admin Reports API validation, AR/EN

Parent #2503, isolated integration branch `Foodex_V2`. This narrow change is separate from completed P307 #2637 (Sales Reports UI/navigation authorization).

## Static source defect

The existing authorized `GET /api/v1/admin/reports/dashboard` endpoint requires a positive `store_id` for store-scoped roles, but its missing/zero-store 422 message was hard-coded English rather than using existing canonical AR/EN `reports.store_required`. The error can reach Arabic Admin/Dashboard integrations. The permission/scope guard and response shape were already implemented; they remain untouched.

## Implementation

The controller uses the **existing** `reports.store_required` key. The first supported AR/EN `Accept-Language` preference (including `ar-KW` and `en-US`) wins; absent supported preferences use the authenticated user's AR/EN locale, otherwise application AR/EN locale, then EN. No raw headers become translation file names. The existing 422 `errors.store_id` structure and Super Admin platform aggregation are unchanged, with no grant or scope bypass.

## Focused HTTP regression

`backend/tests/Feature/V2AdminReportStoreScopeLocaleTest.php` uses an authenticated Retail store-scoped reporter on two real stores and tests Arabic fallback, preferred English/Arabic, unknown-language user fallback, own store success and foreign store 403. It also proves Super Admin platform-wide reporting without store selection.

```bash
cd backend
php artisan test --filter=V2AdminReportStoreScopeLocaleTest
vendor/bin/pint --test app/Http/Controllers/Api/V1/AdminReportController.php tests/Feature/V2AdminReportStoreScopeLocaleTest.php
```

**Impacts:** Backend Admin Reports API message only; existing Dashboard reporting clients can render the selected language without changing HTTP shape. No Dashboard screen/route/Sidebar change, no Mobile Customer/Driver/Van API or Flutter call to this admin endpoint, no RBAC grant or database write in runtime, and no new translations/UI layout. No production/main actions. Exact-head CI must be verified separately before claiming pass, merge or issue completion.
