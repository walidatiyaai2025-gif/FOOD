# V2 #3371 — updater rollback backup preflight

## Scope and placement

This hardening is confined to the existing Admin System Updates recovery path. It changes the rollback runtime, adds isolated Laravel feature regressions, and records this QA evidence. The existing Dashboard route and user-facing error strings stay unchanged. There is no Customer, Driver, or Van API/Flutter/permission impact; B2B remains Van-only and B2C remains Driver-only.

## Recovery safety

Before deleting any release target, rollback now validates the complete manifest and all referenced backup files:

- target and existing arrays must be ordered lists of canonical, unique safe paths;
- every existing path must be present in targets;
- the backup directory must resolve inside the configured backup root;
- manifest and backup paths reject symlinks, and every required backup is a readable regular file.

Malformed, mismatched, partial, missing, or symlinked backup inputs fail closed before live target mutation. A valid rollback still removes newly introduced targets and restores the original backed-up files. This does not reverse database migrations.

## Regression coverage

The new V2UpdaterRollbackPreflightTest covers:

1. a missing later backup after an earlier valid backup, with both live files preserved;
2. an existing path that is foreign to the target list, with the live file preserved;
3. a symlink backup source, with the live file preserved;
4. a valid mix of existing and new release files, restoring the original and removing the new file.

Fixtures use unique test-owned release and backup directories and clean them during teardown. The symlink case skips only when the test filesystem cannot create a symlink.

## Verification

The implementation must remain unmerged until Repository Policy, FOODEX V2 Isolation, and the terminal FOODEX Required CI checks succeed on the exact PR head. CI results must be recorded in the PR before merge. No production or release artifact is produced by this change.
