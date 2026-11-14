# V2 #3519 — Push device DELETE cross-app token boundary

- Scope: `DELETE /api/v1/push/devices/{device}` inside existing `auth:sanctum` API group. Base/merge target: **Foodex_V2 only**. No main, production, release, tag, cPanel or APK publishing.
- Before: `PushDeviceController::destroy` checked only device.user_id, permitting a user with separate Customer/Driver/Van app sessions to revoke another of their *own* app's device using the wrong session token.
- After: the original owner check remains, and `User::tokenCan('app:'.$device->app)` additionally binds the destructive endpoint to its app-specific token. Both denied cases return the existing **404** and do not mutate the device. The normal authorized reply stays `200 {"status":"revoked"}`. No new DB schema, route, request field, translation, or permission grant.
- Exact executable regression: `backend/tests/Feature/V2PushDeviceAppTokenRevocationGuardTest.php` covers same-owner Customer-token DELETE of Driver/Van (404 and intact rows), Driver-token success (only Driver row revoked), Customer- and Van-token success, other-user denial with matching ability, same-owner empty-abilities denial, and unauthenticated 401 denial. Real Laravel HTTP/Sanctum tests; no mocked authorization or FCM network calls.
- Negative boundaries: `User` ownership and app ability must both hold. A session authenticated for only one app cannot revoke the other app's device; app-specific auth and notification transport remain otherwise unchanged.

## Surface and source classification

| Surface | Impact |
| --- | --- |
| Backend/API | Existing delete route guard only; response/status unchanged for valid caller; 404 for previously unintended cross-app token use |
| Dashboard | No route/menu/view/permission/entitlement/readiness changes |
| Customer App | Existing customer token revokes only customer devices; no Flutter change or schema change |
| Driver App | Existing driver token revokes only driver devices; B2C=Driver only remains intact |
| Van App | Existing van token revokes only van devices; B2B=Van only remains intact |
| AR/EN, RTL/LTR, accessibility | No new visible strings/UI; both locale selections use the same existing error code and response |

## Verification gate

Before any merge, verify: original issue claim and current leases; final PR base `Foodex_V2`; no overlapping active file owners; Repository Policy SUCCESS; FOODEX V2 Isolation Gate SUCCESS; exact-final-head FOODEX Required CI incl Laravel/HTTP/security/Pint/PHPStan/localization/MySQL-Redis terminal SUCCESS; source review. No CI run or green status is asserted by this document. Manual cross-app device/staging UAT remains deferred to #2740/#2503, not to this source guard.

Parent #2740 and master #2503 remain open.
