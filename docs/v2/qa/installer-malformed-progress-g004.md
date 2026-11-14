# V2 #3435 — Fail closed on malformed persisted Web Installer progress

**Sources:** FOODEX V2 G004 Web Installer / P112 ZIP first install; parents #2745 / #2846, master #2503. Merge only to `Foodex_V2`; no `main`, release, tags, cPanel or production.

## Existing state integrity gap

The canonical `InstallState::progress()` returned an all-empty first-run state when an existing `install-progress.json` contained invalid/truncated JSON, scalar JSON, or could not be read. A subsequent `token()` or `markStepComplete()` could then replace the previous bytes by atomic rename, silently discarding setup history, admin preparation and token. Atomic writes under ordinary conditions are not proof that a pre-existing but corrupt state is disposable. The stored progress file is a local trust boundary, not an unauthenticated remote input.

Distinct, already-delivered controls:
- #3398 rejects non-integer or out-of-range `completed_step` inside an otherwise valid JSON object.
- #3378 serializes concurrent first-run token generation and leaves a stable filesystem lock inode.
- #3369 prevents repeated step-13 `APP_KEY` rotation.
None of those authorizes resetting an unreadable or malformed *existing* JSON document.

## Implementation and recovery behavior

- If no progress file exists, the legacy initial state remains `step=0/admin=null/token=null`. Normal first-run token generation still persists a new state once under the existing file lock.
- If a file exists and read fails, return a generic `RuntimeException`, without raw contents, path, token, password or stack details in the exception message.
- Decode JSON with explicit error detection. The saved root must be a JSON **object**; invalid/truncated JSON and scalar/list documents raise a generic error. Never silently reset or overwrite their original bytes.
- Existing legitimate object snapshots retain their completed-step strict integer validation, admin and 64-digit token parsing; the symlink/ancestor safety, token-lock, atomic writes, key persistence and idempotent setup-step behavior are untouched.
- A corrupt state requires manual, **authorized recovery from the last verified backup** or a separately audited recovery workflow. Do not automatically delete/overwrite the state, and do not promise that arbitrary setup state can be reconstructed. The existing installer/server error boundary remains in effect.

## Executable regression / acceptance

`backend/tests/Feature/V2InstallerMalformedProgressFailClosedTest.php` uses randomized synthetic storage and an isolated token-lock path. It covers:

1. Truly absent progress: safe initial `step=0`, one token created, stable across repeats.
2. Valid saved step/admin/token: preserve fields and raw JSON bytes without rewriting.
3. Truncated/invalid JSON, literal null/boolean/string, JSON list and empty bytes: direct `progress()`, `token()` and `markStepComplete()` reject before writes; original bytes and no token-lock side effects confirmed.
4. Real GET `/install` on corrupt state fails safely and leaves test `.env`, corrupted progress and install lock untouched.

Exact-final-head acceptance requires Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI, including focused/full Laravel/Pint/PHPStan/Composer security/OpenAPI, AR/EN and real MySQL/Redis install/upgrade/recovery, all terminal SUCCESS.

## Surface and mobile impact

| Surface | Classification |
|---|---|
| Backend Installer | **Affected**: fail-closed corrupted local progress read; normal first install unchanged |
| Dashboard / Web Installer | Existing `/install` route returns controlled generic server failure for invalid persisted state; no new UI/translation keys or navigation |
| Customer | **Not affected**: no public API, authentication, checkout, Flutter source or data contract changes |
| Driver | **Not affected**: B2C→Driver-only assignment/status/collection and Flutter unchanged |
| Van | **Not affected**: B2B→Van-only routes, stock, settlement and Flutter unchanged |

No production .env/user/DB data touched by these tests. This single parser hardening is not signed Setup/Update ZIP acceptance; parent #2745/#2846/#2503 remain open.
