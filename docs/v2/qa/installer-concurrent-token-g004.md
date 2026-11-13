# G004 / P112 — concurrent first-run installer token initialization (#3378)

**Integration:** `Foodex_V2` only. **Parent gates:** #2745, #2846, #2503 remain open. This patch is not signed Setup ZIP, Update ZIP, release or production certification.

## Source and failure

`InstallState::token()` initially read progress without a token, generated a new 256-bit hexadecimal token, then atomically replaced the progress JSON. Two first-run `GET /install` requests could interleave before either write, return distinct tokens and leave one browser with a stale token (419 on its next POST). Atomic rename protects file integrity but does not serialize the whole read/generate/write transaction.

## Bounded implementation

- Keep an installer-owned `<progress-path>.token-lock` regular file as a persistent lock inode. Hold `flock(LOCK_EX)` across a fresh progress read and first-token creation, so all competing initializers reuse one persisted token.
- Check the token-lock path and ancestor symlink boundary before and after opening and after acquiring the lock; compare the opened device/inode with the named filesystem entry. Do not delete the lock file at unlock (that would allow simultaneous locks on different inodes). Permissions are reduced to 0640. Do not log or disclose tokens.
- Preserve existing progress fields, existing valid token, installer lock and installed-guard semantics. No database migration, business seed, public route, controller, update-package or production-data modifications.
- Existing `InstallState` path-symlink hardening from #3280 is preserved. Files owned by active #3367, #3369 and #3372 workers are untouched.

## Test/verification matrix

1. Two sequential actual `GET /install` requests preserve a single 64-character token, no completed progress or install-lock mutation.
2. A previously recorded admin/progress with a missing token retains all fields after token creation.
3. Six independent PHP CLI Laravel bootstraps attempt first-token initialization concurrently, with all returned tokens identical to the persisted authoritative token. Skip only where PHP process creation is unavailable.
4. A symlinked token-lock path fails closed without editing the outside sentinel or creating progress. Test only where the filesystem supports symlinks.
5. Required before merge: focused Laravel test, PHP syntax, Pint, static checks, repository policy, V2 isolation, exact-head full Required CI including Laravel/security/localization and MySQL/Redis install/upgrade/recovery. If a mandatory gate fails, fix it on the same branch/PR and keep the issue open.

## Operational failure/retry

If lock creation/acquisition is denied by hosting permissions, stop the installer with a path-free server error; remedy writable storage via hosting administration and reload `/install`. Retrying does not rotate an existing token. A stale ordinary lock file need not be manually deleted; OS file locks release when a process ends. After successful install, the installer remains closed by the existing installed lock.

## Surface impact

- **Dashboard /install:** affected only during simultaneous first-run requests; no screen text or route changes. Arabic/English/RTL/LTR remain unchanged.
- **Customer:** no public API, login, checkout or Flutter impact.
- **Driver:** no API, route, assignments or Flutter impact; B2C → DRIVER ONLY unchanged.
- **Van:** no API, custody, route or Flutter impact; B2B → VAN ONLY unchanged.

**Acceptance boundary:** Passing this atomic race regression does not prove complete P112 release packaging or production deployment readiness.