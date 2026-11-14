# V2 #3459 — Scheduler persistent lock inode safety

Source: P112 shared-host resilient deployment and scheduler operations; parents #2846 and #2503. PR base **Foodex_V2 only**. No main, release, tags or production.

## Finding and correction
`SchedulerRuntime::tickIfDue()` and `repairManagedCronIfDue()` previously opened their persistent lock files with `fopen('c+')` and truncated timestamps without checking whether the path was a symlink or hardlink. The scheduler runs in the backend process; a linked lock file could corrupt a foreign file.

Both scheduler locks now use the same fail-closed opening helper: reject symlinked leaf or ancestors before open; compare `fstat` and `lstat` regular-file type, device, inode and link count before and after `flock`. No lock file is unlinked during operation. Unsafe paths are ignored without scheduling or file modification; no exception is surfaced to a customer. Normal throttling and restricted-host heartbeat fallback remain unchanged.

## Evidence / acceptance
- New Laravel `V2SchedulerHeartbeatLockSafetyTest`: symlink leaf, hardlink leaf and symlink parent preserve outside files; ordinary lock remains throttled.
- Existing `SchedulerRuntimeTest`: normal due campaign dispatch and one-tick-per-minute behavior.
- Required exact-head Repository Policy, V2 Isolation, Laravel/Pint/PHPStan/security/AR-EN, MySQL/Redis and Required CI are **not claimed complete** until Actions verification.
- Scope is scheduler runtime and its test/documentation. No new route, permission, status, data migration or UI. Backend operational behavior affected; Dashboard installer/updater UI unchanged. Customer API/Flutter not affected; Driver API/Flutter and B2C-only dispatch not affected; Van API/Flutter and B2B-only fulfillment not affected. No new locale strings or responsive/RTL change.
- Final manual hosting/cPanel UAT belongs to #2503, not this atomic code gate.
