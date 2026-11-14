# FOODEX V2 #3404 — Updater execution lock descriptor identity and hardlink safety

**Source:** G005 signed ZIP/Backup & Recovery #2746; P112 #2846; master #2503. **Integration:** `Foodex_V2` ONLY; do not promote `main` or publish/deploy any production artifact.

## Reproduced source boundary

The updater execution lock already uses private storage, symlink ancestor rejection and `flock(LOCK_EX|LOCK_NB)` from completed #3291 and shares the same lock with manual backup and recovery from #3340. However, `is_link()` does **not** identify a regular file with multiple hard links; the old implementation opened the candidate then called `chmod(path,0600)` and locked the unverified descriptor. A locally linked target can therefore have permissions changed unexpectedly. A rename/path replacement between the symlink precheck and exclusive flock could also lead to a lock on an obsolete inode, undermining mutual exclusion.

This is a **local filesystem/hosting ownership boundary**, not a claim that arbitrary remote visitors can write to a trusted private storage folder. It assumes an adversarial or corrupted lock path and prevents proceeding on files not verified as unique regular lock-file inodes. It does not claim to stop a malicious privileged process with the ability to replace the private lock directory at any instant.

## Change and regression

`UpdateManager::acquireExecutionLock()` now:
1. Retains existing absolute-path and ancestor symlink guards.
2. Opens the lock, then compares `fstat()` on its file descriptor with `lstat()` at the path for exact device/inode, requires a regular file and **one hard link** before any permission mutation.
3. Revalidates after exclusive nonblocking `flock`, then applies `0600` and checks descriptor/path identity once more.
4. Closes the handle and releases its own lock on any validation/permission-stage exception. The existing full execute/backup/recovery `try/finally` lock lifecycle remains unchanged.

New isolated Laravel Feature `V2UpdaterExecutionLockInodeIntegrityTest.php` verifies:
- Synthetic hardlinked external file is rejected **before callback execution or chmod**, preserving original file contents and mode. Test skips only if the filesystem cannot create a hard link.
- Ordinary single-link execution succeeds, chmod reaches `0600`, and sequential acquisition is possible.
- A concurrently held lock still fails nonblocking before executing the callback, while a callback exception still releases the lock.
- Existing #3291/#3340 symlink, backup/restore, contention and recovery scenarios are exercised by unmodified full Laravel suite.

All test files are isolated under `storage/framework/testing/` and removed by test teardown. No actual release ZIP, live .env, credentials, backups or user data are manipulated.

## Cross-surface impact matrix

| Component | Impact |
| --- | --- |
| Laravel Backend | Existing ZIP update + manual Backup/Recovery lock path safety. Does not change update signature, checksum, migrations or extraction |
| Dashboard | Existing Super Admin Settings → System Updates; same controls/routes and error handling. No new UI, menu or translation text |
| Customer App | No API, login, checkout or Flutter changes |
| Driver App | No B2C dispatch, collection, API or Flutter changes |
| Van App | No B2B stock, custody, route, API or Flutter changes |
| AR/EN / RTL/LTR / a11y | No strings or UI code changed |

B2B is VAN ONLY; B2C is DRIVER ONLY. Authorization and scope decisions unchanged.

## Acceptance and limitations

Before merging: exact-final-head Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI (PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN, full Laravel tests, real MySQL/Redis install/upgrade/recovery) all completed SUCCESS, plus owner/branch/PR/file-fence recheck. Only SHA-guarded `Foodex_V2` merge after those conditions. Parent G005/P112/master remain OPEN until signed Setup & Update ZIP, production dependency completeness, supported upgrades and recovery are fully certified; a green backend CI here does **not** certify signed release ZIP artifacts or a real cPanel production installation.
