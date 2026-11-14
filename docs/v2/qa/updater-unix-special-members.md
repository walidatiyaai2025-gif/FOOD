# V2 #3412 — fail closed on Unix special-file members in Admin Update ZIP

**Source:** P112, parent #2746 (Admin ZIP Update Center), #2846 (Setup/Update ZIP release), master #2503. **Base:** `Foodex_V2` only. No main, production release, tag, deployment or cPanel operation.

## Authoritative security boundary

The existing Laravel `LaravelUpdateRuntime::archiveEntriesFrom()` already checks noncanonical paths, duplicates, protected runtime targets, symlinks and executable files under static-only public asset roots (#3410). It previously examined Unix external ZIP mode metadata only for symbolic links. ZIPs can also label ordinary-looking filenames as FIFO, character/block device, socket or directory without a trailing slash.

The runtime now rejects non-regular, non-directory Unix ZIP member types **before** `preflight`, `backupFiles` or `extractRelease` can act on the release tree. It retains the specific symbolic-link rejection and permits regular files, absent Unix file-type metadata, and canonical directory entries with trailing slash. The goal is fail-closed package validation, **not** a claim that PHP ZipArchive on cPanel would create device nodes or that a production exploit was observed.

## Scope and user journey

- Reuse the existing `Settings → System → System Updates` Super Admin workflow. This patch changes validation only, not routes, buttons, navigation, translations, credentials or authorization.
- The updater's existing error/rollback interface remains the sole message surface; no additional page or Sidebar is added.
- Owned files are exactly `backend/app/Domain/Updater/LaravelUpdateRuntime.php`, `backend/tests/Feature/V2UpdaterUnixSpecialMemberGuardTest.php`, and this QA document.
- Existing signature, SHA-256, version compatibility, ZIP Slip, symlink, static-file, backup and recovery guards are preserved. A rejecting preflight is **not** evidence that an unsigned or forged package is otherwise authorized.

## Automated acceptance

`V2UpdaterUnixSpecialMemberGuardTest` constructs real `ZipArchive` fixtures with `setExternalAttributesName` (Unix metadata only; it never creates physical special files):

1. FIFO, character device, block device, socket and disguised-directory entries with normal filenames are rejected by each of `preflight`, `backupFiles`, `extractRelease` without changing an existing CSS sentinel, extracting a new file, or creating a file-backup directory.
2. Unix symbolic-link metadata keeps the existing specific rejection message.
3. Regular and missing Unix-type metadata allow a safe CSS/new-text update, then `rollbackFiles` restores existing contents and removes newly installed files.
4. Canonical Unix directory entries with trailing slash are still skipped by preflight.

Run applicable PHP syntax/Pint/PHPStan, focused/full Laravel, Repository Policy, FOODEX V2 Isolation and Required CI with MySQL/Redis installation-upgrade-recovery checks against the exact final PR head. Record terminal run IDs and verdicts before merging. This check alone is not sufficient to certify the full signed Setup/Update package or historical data-preserving upgrade required by parent #2746/#2747/#2846.

## Surfaces and non-impact

| Surface | Evidence-backed impact |
| --- | --- |
| Laravel Backend | Update ZIP runtime preflight/backup/extract validation changed; no service/API response contract change |
| Admin Dashboard | Existing authorized updater rejects invalid ZIP using the existing failure path; no UI/string/navigation modifications |
| Customer Flutter | No public REST API, product, auth or mobile file changes |
| Driver Flutter | No B2C dispatch, collection, status or mobile file changes |
| Van Flutter | No B2B route, inventory, order or mobile file changes |
| AR/EN and RTL/LTR | Existing UI and translations reused; no new rendered labels, route or accessibility behavior |

**Contract preserved:** B2B → Van only, B2C → Driver only; all tenant/store/RBAC and finance SoD constraints unchanged. Parent gates remain OPEN pending their independent real-package, signature, source SHA and upgrade/recovery evidence.
