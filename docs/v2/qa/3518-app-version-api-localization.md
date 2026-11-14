# V2 #3518 — bilingual public app-version API error responses

**Scope:** isolated `Foodex_V2`, parents #2740 and #2503. Source files are `AppVersionController.php` and the existing AR/EN `app_versions.php` catalogs. The closed #2653 work on Dashboard administration is not modified.

## Defect and implementation

The unauthenticated `GET /api/v1/app-version` endpoint previously returned two hard-coded English-only error messages: missing app/platform policy 404, and malformed or non-normalizable version 422. Both responses continue to use the unchanged `{"message":"..."}` JSON shape and original HTTP status. The version evaluator still owns semantic comparison, update status, and supported-version boundaries; no raw exception text reaches clients.

Supported client `Accept-Language` preferences are interpreted in priority order with only `ar` and `en` selected, including regional variants such as `ar-KW` and `en-US`. If neither language is supported, fall back to application locale when AR/EN, else EN. No request parameter, authorization override, or new endpoint is introduced. The authoritative existing `app_versions` translation keys are extended equally in AR/EN.

## Automated tests

The new `backend/tests/Feature/V2AppVersionApiLocaleTest.php` performs real Laravel HTTP requests for each of **Customer**, **Driver**, and **Van**. It verifies Arabic and English 404 for unconfigured policies, Arabic and English 422 for invalid current versions, original status/update JSON for supported requests, and unknown-language fallback. Assertions match the entire error payload and prevent exception leakage.

Focused local command (when PHP/Composer dependencies are available):

```bash
cd backend
php artisan test --filter=V2AppVersionApiLocaleTest
vendor/bin/pint --test app/Http/Controllers/Api/V1/AppVersionController.php tests/Feature/V2AppVersionApiLocaleTest.php
```

### Impact assessment

- **Customer app:** unchanged route, status codes, version-policy schema and update decisions; only user-facing 404/422 message is now localized.
- **Driver app:** same, with no change to B2C dispatch/location authorization or forced-update semantics.
- **Van app:** same, with no change to B2B visits, assignments, or device permissions.
- **Dashboard, finance and ERP:** no application code or business data changes; existing Admin app-version policy remains authoritative.
- **Localization:** both AR/EN catalogs are extended; no new UI/Flutter keys or navigation changes, and RTL/LTR layouts are untouched.

**Acceptance evidence:** CI results must be attached to the original issue/PR after exact-final-HEAD verification. This document does not claim tests or CI passed until independently observed. No main, production, tags, releases, cPanel or APK publication.
