# V2 #3284 — Literal installer credential replacement (P112 / G004)

## Source and atomic scope

Parent: #2745 (G004 — signed Setup ZIP / fresh MySQL install), source umbrella #2846 (P112), master #2503. Integration **Foodex_V2 ONLY**. Not a signed ZIP or production-ready installation acceptance claim.

`InstallerEnvironment::write` previously interpolated the complete encoded `.env` assignment as the **replacement** parameter of PHP `preg_replace`. On existing keys, a password containing literal `$1`, `$2`, or `$0` was interpreted as capture/full-match expansion, not credential text. With `DB_PASSWORD=previous`, the supplied test string `test-only-$1-$2-$0-$$-end` was silently corrupted. There is no need for real secrets or deployment access to reproduce the defect.

The bounded fix retains the existing allowlist, key-pattern matching, string quoting, symlink rejection, temporary file permissions and atomic rename, and changes only the substitution of a matched existing line to `preg_replace_callback` returning the complete literal encoded assignment.

## Placement, surface and authority

- **Actors:** First-run host administrator operating the existing `/install` wizard (database, Redis, SMTP steps). No new role or permission grant.
- **Backend:** affected configuration persistence only. No change to connection contract, `APP_KEY` generation, migrations, seeders, queue, authorization, tenant or company scope.
- **Dashboard:** existing Web Installer wizard reused; **no new route, page, Sidebar, visual component or localization key**. AR/EN RTL/LTR remain the existing wizard's responsibility.
- **Customer:** not affected; no public API, authentication, B2B/B2C store selection or Flutter source/payload change.
- **Driver:** not affected; no B2C dispatch/collection/authentication or Flutter source/payload change.
- **Van:** not affected; no B2B visit/routing/dispatch/authentication or Flutter source/payload change.
- **Data security:** credentials stay in the installer-owned `.env`, not GitHub, logs, release ZIP or the user-facing error message. `InstallerEnvironment` still restricts allowed keys, rejects direct symlinked `.env`, writes `0600`, and uses a temporary file plus rename. The earlier #3209 and #3280 symlink fixes remain independent.

## Executable regression evidence

`backend/tests/Feature/V2InstallerEnvLiteralReplacementTest.php` tests:
1. Existing DB password with literal `$1`, `$2`, `$0`, and `$$` survives an update and unrelated `APP_NAME` remains unchanged.
2. Existing Redis and SMTP credentials with quote/punctuation and dollar-number sequences survive separate key updates and are read back exactly.
3. Appending then replacing a password is deterministic (one key only), preserves other keys and retains `0600` filesystem permissions.

Run `cd backend && php artisan test --filter=V2InstallerEnvLiteralReplacementTest`, then full required PHP lint, Pint, static analysis, Composer security, OpenAPI, localization, Laravel, MySQL/Redis acceptance and exact-head Repository Policy, FOODEX V2 Isolation, terminal Required CI. A CI success on the PR does not certify a signed Setup ZIP.

## Exclusions

No user data, existing production server credentials or live cPanel account is accessed. No changes to `main`, release artifacts/tags or production. Parent #2745/#2846/#2503 remain OPEN for full package-level setup/update acceptance.
