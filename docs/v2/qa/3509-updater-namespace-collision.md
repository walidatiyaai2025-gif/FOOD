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

## Cross-worker canonical source reconciliation (2026-11-14)

At preflight, #3507 acquired the **earlier** exclusive source lease on `backend/app/Domain/Updater/LaravelUpdateRuntime.php` and its original [PR #3513](https://github.com/walidatiyaai2025-gif/FOOD/pull/3513) contains the canonical casefold and file-as-ancestor guard. The overlapping three commits on the original #3509 branch must **not** be separately merged, force-pushed or opened as a competing runtime PR. Preserve their history and wait for #3513 to merge after terminal exact-final-head required checks.

The original isolated `V2UpdaterArchiveNamespaceCollisionTest` adds a distinct behavior-level acceptance matrix not exercised in #3513's preflight-only test: direct `backupFiles()` and `extractRelease()` must reject conflicting entries before writes, while canonical ZIP extraction and `rollbackFiles()` must preserve a pre-existing sentinel. Added a nested ancestor alias case in both ZIP orders. The expected failure text now matches #3513's canonical `Update archive contains a file/directory target conflict.` error. **These tests are not claimed passing on the unmerged #3509 branch** because its original overlapping runtime code still has a different error string. Their next integration step is a carefully reconciled test-only diff against the successfully merged #3513 runtime, followed by exact-head full Laravel/CI. No concurrent production runtime edits are authorized.

Customer, Driver and Van Flutter/API boundaries unchanged; no AR/EN UI strings, permissions or database schema changes. Parent G005 signed package/recovery evidence is independent and still open. No `main`, tags, production, or deployment.


## Post-integration verified test-only continuation — 2026-11-14

The canonical [#3507 PR #3513](https://github.com/walidatiyaai2025-gif/FOOD/pull/3513) is **closed and merged** into `Foodex_V2` at `e7e60feaeb92fdad47fd3c639e1ef383a4b4914d`, after exact source-head Policy [#43588599424](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/43588599424), V2 isolation [#43588599457](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/43588599457), and Required CI [#43588599462](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/43588599462) succeeded. Its implementation provides authoritative source-level case-fold and file/ancestor ZIP target denial. Original #3509 duplicate production runtime edits MUST NOT be reintroduced.

This continuation retains **only** the issue-specific behavioral test and QA documentation against the already merged source, preserving original issue branch history via a non-force merge commit whose resulting tree uses the canonical `Foodex_V2` runtime. The test exercises real temporary ZIPs, including file conflicts in both orders, nested case-aliased ancestor targets, no filesystem writes from direct `preflight()`, `backupFiles()`, and `extractRelease()` calls when invalid, and successful distinct file extraction plus `rollbackFiles()` restoration. The assertions use the canonical runtime error message.

The final #3509 PR must include only `backend/tests/Feature/V2UpdaterArchiveNamespaceCollisionTest.php` and this QA file; it must have its **own** latest-head tests/CI, independently of the successful older #3507 run. This is test coverage of an existing runtime guard, not a second implementation. There are no public API, Dashboard UI, Customer/Driver/Van Flutter or translation modifications, no entitlement/permission grant and no database migration. No release artifact, signed package or production deployment is authorized. Parent G005/P112 and master #2503 acceptance stay open.
