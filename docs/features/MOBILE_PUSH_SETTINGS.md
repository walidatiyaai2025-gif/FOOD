# Mobile Apps & Push Settings

Issue #98 centralizes Customer and Driver runtime/store metadata and push delivery configuration.

- Provider credentials use encrypted model casts and are hidden from serialization.
- Device tokens are encrypted at rest and never returned by APIs.
- mobile_settings.manage controls runtime/store settings; push_settings.manage controls providers; push_settings.test controls test sends.
- Settings mutations and test sends are audited without credentials or device-token values.
- GET /api/v1/mobile/runtime returns non-secret maintenance, update, store-link, release-note, deep-link, readiness and push-capability metadata.
- Authenticated clients register/revoke devices through POST /api/v1/push/devices and DELETE /api/v1/push/devices/{device}.
- Android and iOS device tokens are Firebase Cloud Messaging registration tokens. Both platforms deliver through Firebase HTTP v1; Firebase routes iOS messages through the configured APNs integration.
- Publishing a notification with push/both dispatches through this provider path for matching registered devices.

- Customer native identity: `com.fiftysolution.foodex.customer`; Driver native identity: `com.fiftysolution.foodex.driver`.
- Android creates the `foodex_updates` notification channel; iOS uses the production push entitlement and Firebase/APNs bridge.
- Client token refresh re-registers the device; logout/session teardown revokes the current server device record.
- Notification taps are constrained to approved in-app destinations and foreground pushes surface in-app without bypassing authorization.

## Firebase service-account authentication

For production Firebase HTTP v1 delivery, paste the Google/Firebase service-account JSON for the selected app/platform/environment. FOODEX requires `project_id`, `client_email`, and `private_key`; `token_uri` is optional and defaults to Google's OAuth endpoint. The JSON is encrypted at rest. FOODEX signs a short-lived JWT, obtains an OAuth2 access token, caches it before expiry, and refreshes it automatically. Operators should not paste a manually generated access token for normal production use.

Legacy provider records containing `project_id` plus `access_token` remain supported during migration. Test-send failures preserve the actionable provider/OAuth reason in the administration delivery log.
