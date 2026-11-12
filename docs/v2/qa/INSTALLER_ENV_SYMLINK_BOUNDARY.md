# V2 #3209 — installer .env symlink boundary

Scope: #2745 (G004) and #2846 (P112); integration **Foodex_V2 only**.

## Source behavior and fix

Before the fix, `InstallerEnvironment::ensureEnvironmentFile()` used `is_file()` on the configured .env path, which follows an existing symlink. The subsequent read could consume a different file's content. The installer now rejects both valid and dangling direct symbolic links before reading or writing the configured .env path, and repeats the check after first-file setup. A regular .env keeps the same allowlisted, temporary-file, permission-0600 and atomic-rename behavior. Exceptions intentionally do not include target path contents or credentials.

## Focused automated coverage

- Existing symlink read fails closed; linked target is unchanged.
- Existing symlink write fails closed; linked target is unchanged.
- Dangling symlink cannot be read, written or used to create its target.
- Normal .env write/read still succeeds.

Run: `cd backend && php artisan test --filter=InstallerEnvironmentSymlinkBoundaryTest && vendor/bin/pint --test app/Domain/Installer/InstallerEnvironment.php tests/Feature/InstallerEnvironmentSymlinkBoundaryTest.php`.

## Impact / exclusions

Backend: installer .env configuration only, no API contract or production data migration. Dashboard: the existing /install flow receives the existing RuntimeException error surface; no UI/control changes. Customer: no Flutter or mobile API change. Driver: no Flutter/API change, B2C driver-only unaffected. Van: no Flutter/API change, B2B van-only unaffected. AR/EN: no new UI strings or localization keys. No new accessibility or responsive surfaces.

This **does not** prove full Setup ZIP or Update ZIP readiness; remaining signed provenance, package-level clean MySQL install, web wizard, upgrade/rollback and artifact CI remain in #2745/#2746/#2846. No main/tag/release/production operations.
