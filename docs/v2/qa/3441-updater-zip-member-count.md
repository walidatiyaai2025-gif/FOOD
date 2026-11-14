# V2 #3441 — bounded Update ZIP member count

Source P112 (G005 #2746) / #2846 / master #2503. Integration to `Foodex_V2` only; no main, tags, releases or production cPanel operation.

## Verified security and reliability gap

`LaravelUpdateRuntime::archiveEntriesFrom()` previously enforced a per-member and total **expanded-byte budget** but iterated all `ZipArchive::numFiles` members without a **count budget**. ZIP central directories can contain very many zero-length files or directory entries; those use no expanded file bytes, yet iteration, bookkeeping, backup planning, and path validation scale with the number of records and can exhaust shared-host CPU/RAM. Earlier #3414 expanded-size protection remains necessary but cannot block this case.

## Correction

Before iterating or staging any member, enforce a positive limit:

- `foodex.update_max_zip_members`, default `50000` for full Laravel/vendor Update ZIP bundles.
- `max(1, ...)` prevents a zero/negative override from disabling the bound.
- Every central directory record counts, **including directory members**, because parsing them has a cost even when no file payload is extracted.
- The guard resides in the common `archiveEntriesFrom` method. This ensures **preflight**, **direct backupFiles** and **direct extractRelease** all refuse over-budget archives before backup creation or file activation.
- The default can be raised only when an independently verified larger signed package/hosting capacity requires it; no arbitrary file exclusion, security bypass or package-size change.

## Automated evidence

`backend/tests/Feature/V2UpdaterZipMemberCountBudgetTest.php`:

1. Four members, including three empty files, with a three-member cap: fail closed at preflight/backup/extraction, existing release file and backup state intact.
2. Four members, including zero-length directory records, with a three-member cap: same result.
3. Three members at the cap: preflight passes, file backup succeeds, two zero-byte files install, rollback restores original file and removes new files.
4. Zero configured limit clamps to one; two members fail at all entry points.

Existing size-budget, protected-path, symlink, backup/rollback and ZIP duplicate-target tests are unchanged and should remain green. All exact-final-head Repository Policy, V2 Isolation, Required CI terminal gate (PHP syntax/Pint/PHPStan/security/OpenAPI, Laravel suite, AR/EN, MySQL/Redis install/upgrade/recovery) must succeed.

## Scope and application matrix

| Component | Impact |
|---|---|
| Backend | Update ZIP archive parsing resource bound, before backups or mutations |
| Dashboard | Existing Super Admin System Updates error display receives generic server validation exception; no UI, route or permission change |
| Customer | Not affected; no app API/Flutter/identity/permissions change |
| Driver | Not affected; no B2C delivery/Flutter or API change |
| Van | Not affected; no B2B Van order/Flutter/API change |
| AR/EN, RTL/LTR, accessibility | No translated string, visual screen or navigation change |
| cPanel, shared hosting, VPS | Runtime PHP check only; no user terminal or SSH needed |

Parent G005/P112 remains OPEN for authentic signed release packages, full fresh installs, update/backup/recovery acceptance and final isolated staging verification. No production deployment claim.
