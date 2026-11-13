# V2 #3354 — fail closed on duplicate normalized Update ZIP release targets

**Source:** Unified RBAC/ERP/Sidebar 2026-11-09 document P112, G005. Parent #2746 / #2846; master #2503. **Integration:** `Foodex_V2` ONLY.

## Source-level repro and security impact

The canonical Laravel Update ZIP service `LaravelUpdateRuntime::archiveEntriesFrom` normalized each raw member path using `safeRelativePath`, then returned `array_unique($entries)`. Consequently raw entries such as `backend/config/feature.php` and `backend\config\feature.php` mapped to the **same target** without rejecting conflicting member contents. Different ZIP readers or PHP ZIP member lookups can select different data for the same intended path, undermining unambiguous backup, audit and activation. This is distinct from the previously fixed ZIP Slip/symlink target vulnerability (#3199 / PR #3205), which is retained unchanged.

## Atomic remediation

- The updater enumerates **every** archive file member before any package backup or activation, and maintains a set of normalized target paths. A second occurrence of the same target, even via a different raw archive name, causes a deterministic `RuntimeException`: `Update archive contains duplicate release target paths.`
- Preserve existing canonicalization, protected .env/storage/vendor guards, ZIP symlink checks, release-root symlink verification and hash-version controls. Directory entries remain non-activation entries. No ignored/silent duplicate rows.
- Direct `backupFiles` invocation now parses/validates the complete archive before creating its backup directory; normal `UpdateManager::execute` already preflights prior to maintenance. `extractRelease` likewise scans archive entries before any file writes.
- The Admin Update Center already catches `RuntimeException`, applies its existing error behavior. No new Dashboard view, navigation, translation catalog, user input field, API route or app contract.

## Regression/acceptance evidence

New `backend/tests/Feature/V2UpdaterDuplicateArchiveTargetsTest.php` runs real ZIP fixtures in an isolated test release subdirectory:

1. Preflight rejects slash/backslash target aliases, preserving existing files without backup artifacts.
2. Direct backup rejects aliases **before** creating a backup directory or reading/copying live files.
3. Direct extraction refuses an ambiguous ZIP even if a normal file appears first, before overwriting the existing file.
4. Distinct canonical files preflight, back up, install and roll back normally, restoring an original file and removing a newly added one.
5. Aliased PHP target fails closed. Test fixtures assert distinct raw ZIP member names exist.

Existing `V2UpdaterSymlinkContainmentTest` must stay green; all current `UpdateManager` and ZIP version tests, Laravel suite, PHP/Pint/PHPStan/security, AR/EN, and real MySQL/Redis install-upgrade-recovery plus terminal Required CI must pass on final PR SHA.

## V2 CI policy / initial red-gate rescue

The repository V2 Isolation Gate requires one explicit `MOBILE IMPACT` line that names Customer, Driver and Van together. A structured per-app impact table alone does not satisfy the current machine gate. PR #3356 includes the consolidated marker in its body and keeps the detailed distinct per-app review below. This is PR evidence formatting only, **not** permission to bypass the gate or app acceptance.

## App and deployment impact matrix

| Surface | Impact | Evidence |
|---|---|---|
| Backend | **Affected** | Existing ZIP archive enumeration rejects duplicate normalized target paths. No DB schema or public API changes. |
| Dashboard | Existing Update Center error path only | `system.update` remains server-side Super Admin authorization; error handling existing, no new UI/AR/EN text. |
| Customer | Not affected | No API route, auth, checkout, app source, or localization change. |
| Driver | Not affected | No dispatch/API/Flutter code change; B2C Driver-only remains. |
| Van | Not affected | No Van/route/visit/API/Flutter code change; B2B Van-only remains. |

The site owner still needs the signed versioned Setup ZIP and trusted Update ZIP with independent public-key signature verification, supported 1.0.124/125/127 upgrade evidence, full backups/recovery and final staging acceptance. **This atomic ZIP ambiguity fix does not close #2746, #2747, #2846 or #2503.** It does not publish any release, modify `VERSION`/`Release/`, integrate `main`, install to cPanel or touch production data.

**GitHub delivery:** one branch, one PR targeting `Foodex_V2`, latest exact-head Repository Policy, V2 Isolation and Required CI green before guarded squash merge; close #3354 only after verified merge and tests.
