# FOODEX V2 #3406 — Canonical ZIP member names before backup and maintenance

**Source:** G005/P112 Admin Update ZIP #2746, Release P112 #2846, V2 master #2503. **Integration only:** `Foodex_V2`. Never change `main`, publish releases, or deploy to production.

## Exact source defect

The canonical update runtime `LaravelUpdateRuntime::archiveEntriesFrom()` accepted a raw ZIP member name, transformed it using `safeRelativePath()` and returned only the normalized path. The runtime then uses that normalized path at `ZipArchive::getStream()` to extract contents. If a ZIP contains a *single* padded or backslash-separated name, such as ` backend/app/Foo.php` or `backend\\app\\Foo.php`, the raw member name and resulting lookup name are not identical. Preflight could pass, file backup and maintenance could begin, then extraction would attempt to read an entry under a different name. Depending on ZIP library name matching, it may fail after service interruption. Rejecting ambiguity at the archive boundary is safer and deterministic.

Prior #3354 explicitly rejects duplicate normalized targets; #3402 detects migration members before trusting a backup declaration. Neither requires a **single** ZIP member name to match the canonical extracted lookup. This fix preserves the existing duplicate-target check priority and its exact error, then rejects noncanonical raw members before any mutations.

## Implementation

- In the shared archive iterator, require `$name === $relativePath` after existing path safety and duplicate checks, using the established error `Update archive contains a non-canonical path.`
- No changes to package signature, SHA-256, permissions, file staging, rollback, Super Admin authorization, release notes, migration execution or live configurations.
- The same iterator feeds preflight, file backup and direct extraction, so noncanonical entries fail before the first release file is written or backup directory is created.
- ZIP producers must generate forward-slash paths without extra leading/trailing whitespace. This is compatible with the ZIP format's canonical path separator convention; malformed producers receive an explicit early rejection.

## Isolated tests

New Laravel Feature `backend/tests/Feature/V2UpdaterNoncanonicalZipMemberPreflightTest.php` writes actual ZIP fixtures with unique synthetic release-directory names. It verifies:

- A single leading-space, trailing-space, tab-prefixed or backslash-separated member is rejected at preflight before backup/maintenance, even when there are no duplicate entries.
- A mixed ZIP with valid first entry and malformed second entry cannot create a backup or install/replace the first entry in direct backup/extraction paths; an existing sentinel remains unchanged.
- A two-file canonical ZIP still passes preflight, backs up existing files, extracts successfully and rolls back to the prior state.
- Raw archive member names are inspected after fixture creation to ensure the test genuinely exercises the noncanonical names; no production package, stored secret or user data is involved.

Existing #3354 duplicate-path, #3199 symlink, #3402 migration and other security tests remain unmodified and are included in full Laravel CI.

## Cross-surface impact

| Surface | Classification |
| --- | --- |
| Backend | ZIP entry preflight/backup/extract rejection only, identical Super Admin access policy |
| Dashboard | Existing Settings → System → System Updates reports invalid package through current error handling, no new screen/route/menu/translation |
| Customer | No mobile Flutter change, API/auth/order or B2B/B2C contract change |
| Driver | No Flutter or dispatch/API change, B2C DRIVER ONLY |
| Van | No Flutter or route/consignment/API change, B2B VAN ONLY |
| Arabic/English, RTL/LTR, accessibility | No existing text/layout or navigation change |

## Acceptance evidence boundary

Require the exact-final-HEAD Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI Gate all completed SUCCESS, including PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN, full Laravel and real MySQL/Redis install-upgrade-recovery. Check changed paths/leases/base before SHA-guarded merge **only into Foodex_V2**, then close only this atomic defect. Parent #2746/#2846/#2503 stay OPEN for the signed actual Setup/Update ZIP artifacts, supported historical upgrade with retained data, tested recovery and production readiness. The synthetic security test alone is not a release certification.
