# V2 #3507 — Update ZIP case-insensitive target collision guard

## Source and isolation
- Source P112; atomic follow-up to G005 #2746, P112 #2846 and master #2503.
- Integration target **Foodex_V2 only**, no main, releases, signing/publishing, tags, cPanel or production deployment.
- Verified independent gap: `LaravelUpdateRuntime::archiveEntriesFrom()` previously indexed ZIP target names using a case-sensitive array; two differently cased PHP file targets could alias on a case-insensitive shared host. This does not assert a production exploit.
- Distinct from merged #3499 (protected .env and .env.* variants).

## Implementation boundary
- Canonical single Update ZIP archive reader computes UTF-8 lowercase target keys and rejects any two file entries mapping to the same case-insensitive target. The error is the existing `Update archive contains duplicate release target paths.` preflight exception.
- It separately rejects a file member that shadows an ancestor directory (or vice versa) in either entry order, even through a case alias. This protects deterministic extraction/rollback before maintenance or backup.
- Canonical single files, distinct code targets and nested directories remain valid. Existing ZIP member limit, expansion limit, exact duplicate, noncanonical entry, symlink, path-traversal, protected runtime, public executable and resource checks are preserved.
- No new endpoint, UI navigation, migration, translation key, permission, package manifest format or public app API.

## Executable regression
`backend/tests/Feature/V2UpdateCasefoldTargetCollisionTest.php` constructs real temporary `ZipArchive` members and runs `LaravelUpdateRuntime::preflight()`, not just a string helper:
- Denial: `Config.php` / `config.php`, nested source folder aliases, Blade template aliases.
- Denial: file/directory conflicts in both ZIP orders, including upper/lower-case parent segments.
- Positive: multiple distinct PHP/Blade source paths and normal nested directories accepted.
- Preflight only: no `extractRelease()`, backup, migration, live `.env`, user data or file changes.

## Runtime and app impact matrix
| Surface | Assessment |
| --- | --- |
| Backend updater | Changed: ZIP preflight fails closed on case-insensitive target collisions and file/directory shadows |
| Dashboard | Existing Super Admin System Updates Verify/Install surfaces return pre-existing failure shape; no new route/UI, server authorization intact |
| Customer | No public API or Flutter delta |
| Driver | No public API or Flutter delta; B2C=DRIVER ONLY unchanged |
| Van | No public API or Flutter delta; B2B=VAN ONLY unchanged |

## Required exact-head checks
- PHP `-l` on runtime + focused test; Laravel Pint.
- `php artisan test --filter=V2UpdateCasefoldTargetCollisionTest` and applicable full Laravel suite.
- Security/Composer audit/PHPStan, AR/EN quality, Repository Policy, FOODEX V2 Isolation and FOODEX Required CI Gate (real MySQL/Redis tests where applicable) must finish GREEN on **the final PR head** before merge.
- On successful source-complete and exact-head CI, squash merge only `Foodex_V2` with expected SHA, verify merged state and record tested SHA, integration SHA and CI run URLs; close only atomic #3507. Keep #2746/#2846/#2503 OPEN for signed Update ZIP, full recovery and business/staging acceptance.
