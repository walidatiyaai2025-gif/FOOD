# FOODEX 1.0.48 Release Notes

Status: final Customer Commerce V4 corrective production distribution.

## Release identity

- Dashboard: `1.0.48`
- Customer app: `1.0.48+48`
- Driver app: `1.0.48+48`
- Customer runtime/footer identity: `1.0.48`
- Driver runtime/footer identity: `1.0.48`
- Driver diagnostics current identity: `1.0.48`
- Driver diagnostics build identity: `48`
- Published `1.0.47` remains immutable and is not reused.

## Included changes

- Preserve the owner-approved Customer runtime baseline from #794, including the unified Home, fixed five-icon footer, signed-in store switching, Dashboard-driven images and store-origin-aware commerce routing.
- Canonicalize Retail merchant identity and reuse the exact linked Wholesale purchasing account without duplication (#797).
- Hide and authoritatively reject purchases from a merchant's own Retail store while leaving Wholesale and other Retail stores purchasable (#798).
- Split My Orders into isolated Wholesale and Retail tabs using authoritative `channel=b2b` / `channel=b2c`, while preserving immutable original `store_id + channel` provenance (#799).
- Bind Dashboard Retail primary owner/manager to the canonical commerce identity and safely remove stale entitlement on reassignment (#800).
- Include final integrated acceptance #775 / PR #805: Backend/MySQL, Customer Android/iOS, Driver Android/iOS, runtime screenshot evidence, App Preview parity, exact auth resume and legacy Business Login / Choose Store purge guards.

## Dashboard update bundle

- Target version: `1.0.48`
- Minimum current version: `1.0.6`
- Contains migrations: generated manifest is authoritative.
- Requires full redeploy: `false`
- SHA-256: generated package metadata and immutable release registry are authoritative.

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.
