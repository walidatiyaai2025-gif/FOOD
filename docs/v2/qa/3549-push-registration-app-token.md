# V2 #3549 — authenticated push registration matches caller app token

**Source:** #3549, parent #2740 and master #2503. **Integration target:** `Foodex_V2` only. This is a narrow server-side app-token isolation fix; no release or production action.

## Preflight and reproduced source-level discrepancy

`PushDeviceController::upsert` validated the caller-controlled `app` but required `app:driver` only for the Driver branch. The Customer branch checked customer affiliation without `app:customer`, while the Van branch could accept `van.login` with a token for a different app. Therefore a multi-persona same-user session could potentially register a foreign-app push endpoint; this is not evidence of an observed production exploit. #3519/PR #3529 separately hardened DELETE revocation; this fix addresses **authenticated POST registration** only.

Source review included `AGENTS.md`, V2 isolation governance, API authoring skill, existing push tests, current three-app Firebase client payloads, and open Foodex_V2 PR filenames. No concurrent open PR touched `PushDeviceController.php` during preflight.

## Backend behavior

For authenticated POST, after validation and before any database mutation or app-specific role/scope branch, the backend enforces `$user->tokenCan('app:'.$data['app'])` and returns the already-documented HTTP 403 when the calling app token does not match the declared app. This complements, never replaces, existing user account and Driver/Van role/store authorization.

Anonymous `POST /api/v1/push/devices/guest` retains its Customer-only validation and the required `install_id`. DELETE revocation's existing 404 behavior and all device request/response fields are unchanged. OpenAPI documents the matching bearer app ability without altering the schema.

## Reproducible Laravel HTTP regression

`backend/tests/Feature/V2PushDeviceAppRegistrationAbilityTest.php` uses real `RefreshDatabase`, seeded canonical `VAN_OPERATOR` permission and a same-user Customer+Van fixture to prove:

- Customer-token → Van registration returns 403 without creating a device, whereas matching Van token creates it.
- Van-token → Customer registration returns 403, including attempts to re-register an existing token, with the original device preserved.
- Missing app ability and Driver-token → Customer fail 403, and a Driver token without Retail driver eligibility is denied.
- Unauthenticated authenticated route returns 401; anonymous Customer registration remains 201 and anonymous Van registration remains 401.

Run from `backend/`:
```bash
php artisan test --filter=V2PushDeviceAppRegistrationAbilityTest
vendor/bin/pint --test app/Http/Controllers/Api/V1/PushDeviceController.php tests/Feature/V2PushDeviceAppRegistrationAbilityTest.php
```

## Impact matrix

- **Backend/API:** token-ability denial for cross-app POST, existing 403/error response, unchanged schema and persistence for legitimate clients.
- **Customer:** authenticated Firebase client already declares Customer and uses its app session; guest Customer endpoint remains available. No Dart change.
- **Driver:** authenticated app already declares Driver and retains additional B2C driver/role checks. No Dart change.
- **Van:** authenticated app already declares Van and now must present matching Van token even when account has `van.login`. No Dart change.
- **Dashboard:** existing Push Settings/admin test send and provider credentials unaffected; no UI/locale strings or routes changed.
- **B2B/B2C:** Van-only wholesale and Driver-only retail enforcement preserved; no new financial/order authorization, no migration, no secrets.

## Acceptance limitations

Source implementation and regression committed to isolated issue branch. Do **not** claim completed, merged, or production-ready until real exact-final-head Repository Policy, V2 Isolation and FOODEX Required CI including full Laravel/security/AR-EN/MySQL/Redis terminal SUCCESS, review and merge verified to `Foodex_V2`. Broader real-device QA remains in #2740/#2503.
