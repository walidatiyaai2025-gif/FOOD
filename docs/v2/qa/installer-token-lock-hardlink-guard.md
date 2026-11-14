# FOODEX V2 #3408 — Installer token lock hardlink integrity

**Sources:** Setup ZIP/Web Installer G004 #2745; Release P112 #2846; master V2 #2503. Integration: `Foodex_V2` ONLY. No main, release, tag, cPanel or production deployment.

## Source discrepancy

`InstallState::token()` creates or opens a persistent installer token lock and uses `flock` to serialize initial token generation. The existing `assertTokenLockFile()` checked that the open file descriptor and `lstat()` on its pathname agree by device and inode. It rejected symlinks and atomically replaced paths through the ancestor checks.

A filesystem **hardlink** is not a symbolic link. A path hardlinked to an unrelated regular file has identical device/inode values, so it passed those checks. The next `chmod(path, 0640)` would modify the permissions of the unrelated hardlinked file. This is a local filesystem trust/integrity concern on compromised or corrupted installer paths; it is **not** evidence that a web visitor can plant a hardlink in the private server storage directory.

## Bounded change

The existing `assertTokenLockFile()` now additionally requires:

- both `fstat()` and `lstat()` to describe a regular file, not a directory, symlink or special device;
- each to have **exactly one hard link** (`nlink === 1`);
- the existing device/inode identity agreement.

These checks already occur **before chmod** and **again after the blocking exclusive flock**. No new lock, token-rotation, timeout or credential behavior was introduced. Existing token persistence, APP_KEY, setup lock, JSON progress writes, symlink ancestors and database migrations remain unchanged.

## Test coverage

New isolated Laravel Feature `backend/tests/Feature/V2InstallerTokenLockHardlinkGuardTest.php`:

- Creates a synthetic unrelated file with explicit 0644 mode, links it to the configured installer token lock path, and verifies token creation is rejected with the existing generic error. Its original bytes and permissions remain unchanged, and no installer progress/token state is written.
- Accepts the normal single-inode token lock, checks a canonical 64-character hex token and stable replay, verifies mode 0640, and ensures progress remains at step zero.
- Uses a private testing directory under `storage/framework/testing`; teardown removes temporary files only. A hardlink-creation test is skipped only if local test filesystem lacks hardlink support.

The prior #3378 concurrent-token, #3280 symlink and #3398 progress-contract tests remain in full Laravel CI unmodified.

## Cross-app impact

| Surface | Status |
| --- | --- |
| Backend | First-run installer token lock safety only |
| Dashboard | Existing web setup wizard; no route, menu, Blade, validation text or Super Admin scope change |
| Customer | No API, identity, Flutter or commerce changes |
| Driver | No API, B2C assignment/collection or Flutter changes |
| Van | No API, B2B route/stock or Flutter changes |
| AR/EN, RTL/LTR, accessibility | No new strings, layout or navigation |

B2B=VAN ONLY and B2C=DRIVER ONLY are unchanged.

## Acceptance

Require the **exact final PR HEAD** to pass Repository Policy, FOODEX V2 Isolation, FOODEX Required CI (PHP Syntax/Pint/PHPStan/security/OpenAPI, AR/EN, full Laravel test suite and real MySQL/Redis clean-install, upgrade and recovery). Verify branch/lease ownership and that the PR changes only the three stated paths before SHA-guarded merge to `Foodex_V2` only. Close #3408 only after verifying source integration. Parent #2745, #2846 and #2503 remain OPEN for actual trusted signed ZIP artifact creation, supported historical upgrade and recovery tests and complete acceptance.
