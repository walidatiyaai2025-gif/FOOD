# V2 #3509 — Update ZIP release namespace collision preflight

## Source, ownership, and priority

- Parent: #2746 (G005 Admin Update Center), #2846 (P112), master #2503. Isolated integration branch: **`Foodex_V2` ONLY**.
- Reuse canonical `backend/app/Domain/Updater/LaravelUpdateRuntime.php::archiveEntriesFrom()` preflight called by `preflight()`, `backupFiles()`, and `extractRelease()`. No second ZIP parser or independent policy engine.
- Prior #3354/#3356 rejects exact and normalized duplicate ZIP targets. #3509 covers distinct **ASCII-case alias collisions** and **file-as-parent conflicts** that exact-key comparison did not catch. Both may otherwise be material on shared-host and case-insensitive filesystems. No assertion of a live exploit.
- All 18 open V2 PR file fences reviewed before claim; none edited the three issue-owned paths.

## Fail-closed safety rules

1. Keep current ZIP limits, Unix special-file/symlink denial, canonical path guards, SHA-256, signed-release trust boundary, and protected runtime files.
2. Only regular-file entries are activation targets. After parsing **all** members, check a lowercase lookup to deny `Config.php` and `config.php` targeting the same destination on case-insensitive hosts, without treating unrelated filenames in different directories as duplicates.
3. Reject `module` as a file when `module/Feature.php` is another member, even when entries are reversed or the ancestor differs in ASCII case. Detection precedes any file backup, maintenance or extraction.
4. No changed live `.env`/credentials, uploads or database, and no new user-visible translations or updater routes. No implicit rollback claim for unsafe database migrations.

## Regression matrix

`backend/tests/Feature/V2UpdaterArchiveNamespaceCollisionTest.php` creates actual temporary ZIP files with isolated temporary release and backup directories, and checks:
- Case-only alias targets rejected by preflight, direct backup and direct extraction before mutation.
- File-parent collision rejected in both ZIP ordering variants.
- Case-alias file-parent collision rejected.
- Existing file/sentinel and backup directory remain untouched on each rejection.
- Safe distinct ZIP members, including differing case under **different** parent directories, still pass preflight, install and verified file rollback.

Required exact-final-head validation: PHP syntax, Pint, PHPStan, Composer audit, OpenAPI, focused Laravel test, full Laravel suite, AR/EN localization and real disposable MySQL/Redis install-upgrade-recovery. Exact-head Repository Policy, V2 Isolation and terminal required-ci-gate must be SUCCESS before merge; no CI waiver. Final human UAT is tracked by #2503.

## Product placement and downstream effect

| Surface | Authority, UX and impact |
| --- | --- |
| Backend | Hardened existing Update ZIP namespace preflight before mutation |
| Dashboard | Existing Super Admin Settings → System → System Updates and localized existing failure reporting; no new screen, route, sidebar item, grants or approval path |
| API | No public/management API schema change; existing updater action protected as before |
| Customer | Not affected; no Flutter source or API/identity/order data change |
| Driver | Not affected; no Flutter or dispatch change, B2C → DRIVER ONLY maintained |
| Van | Not affected; no Flutter or van assignment/route change, B2B → VAN ONLY maintained |

No new Dashboard UI and thus no visual-pattern changes. Reuse → Extend the canonical existing update runtime, not a separate page. G005 trusted signed publishing and actual Setup/Update ZIP release artifacts remain unfinished/unproven until their independent gates. No `main`, release, tags, production, cPanel or deployment.
