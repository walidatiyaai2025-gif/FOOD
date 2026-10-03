# FOODEX 1.0.49 Release Notes

Status: own-store context hardening and Dashboard catalog imagery distribution.

## Release identity

- Dashboard: `1.0.49`
- Customer app: `1.0.49+49`
- Driver app: `1.0.49+49`
- Customer runtime/footer identity: `1.0.49`
- Driver runtime/footer identity: `1.0.49`
- Driver diagnostics current identity: `1.0.49`
- Driver diagnostics build identity: `49`
- Published `1.0.48` remains immutable and is not reused.

## Included changes

- Reject stale remembered Retail commerce context when the signed-in user no longer has that store in the purchasable Retail list, covering the Marketplace store shortcut and persistent footer.
- Return direct Retail home/catalog navigation to the Marketplace when the backend reports `SELF_STORE_PURCHASE_NOT_ALLOWED`, while retaining backend self-store purchase protection as the authority.
- Expose Dashboard Category images and Brand images through Wholesale platform/public/authenticated catalog APIs and Brand imagery through Retail product APIs.
- Render real Wholesale Category and Brand visuals from Dashboard media, preserve primary Product images, and use deterministic fallback icons only when Dashboard media is absent or cannot load.
- Render Brand imagery on Retail Product cards.
- Add immediate Dashboard upload previews for Product, Category and Brand images before save.
- Include #812 / PR #813 regression coverage and green Backend/MySQL, Customer Android/iOS, UI Visual QA and update-package validation.

## Dashboard update bundle

- Target version: `1.0.49`
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
