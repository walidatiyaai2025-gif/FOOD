# FOODEX 1.0.70 Release Notes

Status: immutable production release registered through release PR #1260.

## Release identity

- Dashboard / repository release: `1.0.70`
- Customer app source identity: `1.0.70+70`
- Driver app source identity: `1.0.70+70`
- Van app source identity: `1.0.70+70`
- Customer runtime/footer identity: `1.0.70`
- Driver runtime/footer identity: `1.0.70`
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Include Issue #1258 / PR #1259: a global Dashboard popup for new orders created by the Customer App.
- Use the existing dashboard operational-notification domain as the authoritative source and tag creation source explicitly instead of inferring from route/user-agent text.
- Add a lightweight incremental feed with cursor bootstrap, scoped authorization, no-store cache semantics and short visible-tab polling with retry backoff.
- Queue multiple new orders without overlap or duplicates and persist the cursor per browser session.
- Open the exact Order Operations record from the popup and support Arabic/English plus RTL/LTR responsive Dashboard layouts.
- Exclude Dashboard-, Van- and Integration-created orders from this popup.
- Keep the Dashboard update package independently deployable; installing it does not replace mobile APKs already installed on devices.

## Validation contract

- Required CI must pass backend tests, PHPStan/lint/security/OpenAPI, MySQL/Redis install-upgrade-recovery acceptance, localization and Dashboard runtime visual evidence.
- The generated `FOODEX-Update.zip` must target `1.0.70`, remain compatible with current `1.0.69` installations, and report no full redeploy requirement.
- The exact generated package SHA-256 must be registered before merge to `main`.
