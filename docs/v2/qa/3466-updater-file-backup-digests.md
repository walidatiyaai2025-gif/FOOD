# V2 #3466 — file rollback backup integrity (P112 / G005)

**Owner:** issue #3466; parents #2746, #2846, #2503. **Integration:** `Foodex_V2` only; no main, release, tag, production or deployment.

## Source discrepancy and concrete failure mode

Before this change, `LaravelUpdateRuntime::backupFiles` copied original release files and wrote only `targets` / `existing` in `files.json`. `rollbackFiles` preflighted path trust, manifest list shape and existence/readability of each backed-up source, but never checked the contents. Corruption of a regular readable original could therefore be silently restored over a live updated file. Original #3371/#3375 handles missing/symlinked/cross-root snapshots, not content digests; #3396 handles **database** backup provenance. This change does not duplicate either.

## Scoped design

- Each **new** file backup writes `file_snapshot_format: 1` and a `file_sha256` JSON object mapping the exact existing-file relative paths to lowercase SHA-256 of **copied backup bytes**. Empty original files get the empty-content digest; newly introduced files are listed as targets, but correctly have no backup-file digest.
- A **versioned** rollback requires exactly one well-formed digest for every `existing` path, no extras, no duplicates and an exact SHA-256 match before deleting or overwriting any live target. A malformed version marker/digest field fails closed. The full original path, symlink, manifest-list and source-file safety checks remain mandatory.
- Historical **unversioned** `files.json` manifests remain restorable for compatibility. They have existence/path preflight only, and are **not** retrospectively certified intact or authentic. A party with write access to the private backup tree could remove new integrity metadata to mimic a legacy format; SHA-256 alone is not a signed/HMAC authenticity boundary. Operational backup-root access control remains essential; the larger G005 acceptance must address trusted backup provenance and package signing separately.
- This is file backup corruption detection, **not** a guarantee that irreversible DB migrations can be rolled back. Existing DB verification and fail-safe maintenance / recovery semantics stay unchanged.

## Focused automated evidence

`backend/tests/Feature/V2UpdaterFileBackupDigestTest.php` constructs real synthetic ZIP files in a private testing release subtree and verifies:
1. New SHA-256 manifest for original files, including zero-length files; restore originals and remove newly-added file.
2. Modified and truncated-but-readable backup source fails **before any live file is changed**.
3. Missing, extra, invalid and incorrectly typed digest entries; invalid/missing version marker all fail closed and preserve current live files.
4. Historical hashless manifest restore stays compatible without falsely implying integrity.
5. All-new-file snapshot emits an empty object and correctly rolls back by removing the new file.

CI acceptance requires actual terminal `Repository Policy`, `FOODEX V2 Isolation Gate`, and `FOODEX Required CI Gate` SUCCESS on the **latest PR head**, including Laravel tests, security/static validation and relevant MySQL/Redis installation/updater jobs; no shortcut or CI waiver. `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` is permitted only after source review and SHA-guarded merge to `Foodex_V2`. Parent #2746 remains OPEN for signed update ZIP, staging/real-package preservation/upgrade/recovery and full final G005 acceptance.

## Cross-surface and risk review

- **Backend/updater:** affected. This adds checks before destructive file rollback.
- **Dashboard/Admin:** no new route or UI; backend result remains existing localized recovery reporting. No new strings, permission or audit role.
- **Customer:** no API or Flutter change; B2B/B2C contracts unchanged.
- **Driver:** no API or Flutter change; B2C Driver-only retained.
- **Van:** no API or Flutter change; B2B Van-only retained.
- **AR/EN / RTL/LTR / accessibility:** unchanged UI and translation keys; pre-existing HTTP/Super Admin gates are unchanged.
- No live data, credentials, release artifacts or external storage are accessed by tests.
