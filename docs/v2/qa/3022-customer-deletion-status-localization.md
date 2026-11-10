# V2 Mobile Customer P132 — account deletion response localization

Issue #3022, linked backend Issue #2855 / PR #3019; source P132, parent #2503. Target: **Foodex_V2 only**; no main/release/deploy.

## Placement and authority
- Domain: Customer identity/privacy; entity: current authenticated customer's deletion request; role: B2C/B2B Customer.
- Goal: understand whether deletion completed, is being processed, or requires retention/operational review, without mistaking the request for completion.
- Workflow: infrequent account self-service, mutation with password confirmation.
- Existing parent: Customer Account screen, its current Request account deletion button and confirmation dialog. Canonical API: authenticated POST/GET `/api/v1/account-deletion`.
- Decision: **Reuse/extend** existing dialog and SnackBar, not a new route, screen, navigation entry or permission. Pattern: existing account action/confirmation. Normal discoverability stays within Customer Account.
- Navigation: unchanged. Desktop/tablet: not applicable to native Flutter surface; compact mobile and tablet widgets retain existing responsive composition. AR RTL / EN LTR use the Material locale.
- Data: server-returned status only, never infer from optimistic state. Existing password verification, no plaintext password persistence, no invoice amounts or other sensitive finance metadata in the label.
- Status handling: COMPLETED, PENDING_RETENTION, OPERATIONAL_REVIEW_REQUIRED, PROCESSING, REQUESTED and CANCELLED each have AR/EN human-readable copy; unknown/missing status is non-affirmative. Network/password errors have localized, actionable generic language; no internal API error codes presented.

## Test matrix and impact
1. Pure Flutter tests for AR/EN for all statuses, unknown/missing, password and connection error.
2. Widget test using the existing Customer Account deletion action, confirmation/password form and authoritative PENDING_RETENTION API response; no backend mutation mock can confer permissions.
3. Customer **affected**: status copy only; B2B/B2C authenticated request and status paths preserved.
4. Driver **not affected**: existing managed operational identities and their API behavior unchanged; no Driver app code or navigation.
5. Van **not affected**: `VAN_OPERATOR` remains subject to managed offboarding, no Van app API/fulfillment change.
6. Required exact-head Flutter format/analyzer/widget tests + AR/EN localization + isolation and repository policy CI must pass. Manual isolated staging on narrow/wide devices, AR RTL/EN LTR, text scale, focus, semantics and offline handling remains necessary before closure.

No production, version tagging, zip promotion, main or cPanel change is authorized.
