# V2 #3199 — Updater symlink containment (P112 / G005)

**Parent:** #2746, #2846, #2503. **Integration:** `Foodex_V2` only; no `main`, no release/tag, no deployed updater execution.

## Audit and risk

The existing FOODEX ZIP updater validates member names for absolute paths, parent segments, and protected directories, and validates SHA-256. A syntactically safe member such as `some-link/sentinel.txt` can still escape the canonical release root if `some-link` already exists as a filesystem symlink. The original backup and rollback routines followed such paths, and ZIP Unix symbolic-link members were not explicitly rejected. A SHA-256 value supplied with the same untrusted package is not a cryptographic authenticity proof.

## Implemented bounded protection

- `LaravelUpdateRuntime::archiveEntriesFrom` rejects Unix ZIP entries marked as symbolic links using their external attributes.
- `assertSafeReleaseTarget` walks every path component from the canonical release root and rejects existing symlinks, including dangling symlinks and links at the final target.
- The target guard runs in `preflight`, before file backups, immediately before extraction (and after ensuring the target directory), and during both delete and restore phases of rollback. It also rejects non-canonical dot/empty segments.
- Ordinary real files and nested folders remain supported. No permission grants, UI labels, routes, database data, signing mechanism, `.env`, uploads, or storage behavior were intentionally changed.

## Deterministic security regression

`backend/tests/Feature/V2UpdaterSymlinkContainmentTest.php` uses throwaway ZIP files and uniquely named release-root fixtures, with an external sentinel file whose content must remain unchanged. Its cases cover:

1. preflight rejection through an existing directory symlink;
2. backup rejection through the same link;
3. direct extraction rejection even if the caller omitted preflight;
4. rollback rejection of a poisoned backup manifest targeting the link;
5. dangling symlink target rejection;
6. Unix symlink ZIP member rejection;
7. ordinary ZIP preflight and safe file extraction.

Expected verification: `php artisan test --filter V2UpdaterSymlinkContainmentTest`, `vendor/bin/pint --test app/Domain/Updater/LaravelUpdateRuntime.php tests/Feature/V2UpdaterSymlinkContainmentTest.php`, then required full backend, AR/EN, Repository Policy, V2 Isolation and exact-head Required CI jobs.

## Impact and limitations

| Surface | Impact |
| --- | --- |
| Laravel updater / administrative ZIP activation | Affected; backend-only filesystem containment |
| Dashboard | Existing privileged System Updates controller and `system.update` Gate unchanged; ensure authorized management flow still works |
| Customer Flutter | No endpoint, auth, data model or runtime changes |
| Driver Flutter | No endpoint, fulfillment or wallet changes; B2C Driver-only invariant unchanged |
| Van Flutter | No endpoint, route, assignment or session changes; B2B Van-only invariant unchanged |

This issue does **not** certify package-signing authenticity, concurrent update exclusion, production dependencies, MySQL clean install/upgrade/recovery, or complete installer/update delivery. Gates #2745/#2746/#2846 must remain open until these checks are exercised against the actual generated ZIPs on the final V2 SHA. In particular, do not treat a successful checksum or a code-only PHPUnit test as release-ready proof. Any update that races an adversarial process replacing directories between path validation and file activation requires deployment-level filesystem ownership and privilege boundaries in addition to these checks.

## Execution handoff

Record exact PR head, checks and merge result in Issue #3199; never claim tests passed solely because the test file was committed.
