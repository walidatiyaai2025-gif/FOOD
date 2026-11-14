# V2 #3471 — Watchdog terminal state vs lease takeover

Parent: #2931 (V2 5-minute sweeper), master #2503. Integration target: `Foodex_V2` only.

## Reproduced defect

Before #3471, `.github/scripts/v2-lease-watchdog.js::parseClaim` defaulted a missing `STATE` to `ACTIVE` and rejected only a small blacklist. Any `foodex-worker-state:v1` comment with an `OWNER` and a valid GitHub comment timestamp but `GATE_APPROVAL / WORKER_RELEASED`, `BLOCKED_DESIGN_DEPENDENCY`, `MERGED_PENDING_ACCEPTANCE`, or a previously unseen terminal spelling was treated as a live worker lease. If a stale worker label remained and a gate label was lost, the sweeper could emit a false 30-minute `worker:handoff-ready` takeover even though no coding lease existed.

This is a queue-integrity and duplicate-work risk, not user-facing bypass or evidence that the isolated V2 schedule runs on GitHub's default branch.

## Atomic fix

`parseClaim` now accepts only explicitly documented execution states: `ACTIVE`, `WAITING_CI`, `WAITING_EXACT_HEAD_CI`, `WAITING_REQUIRED_CI`, `CI_FIX`, `CI_RUNNING` and `REQUIRED_CI_RUNNING`. Compound state blocks starting with `ACTIVE` remain valid. All unknown, missing, released, integrated, gated or blocked primary states clear prior claims. Unknown formats **fail closed**, leaving existing labels untouched for manual reconciliation rather than auto-releasing a live branch.

The pre-existing 30-minute timeout, commit-only proof, exact-head CI reservation, merged-PR guard, handoff idempotency and GitHub pagination safeguards are unchanged.

## Verification

Command: `node --test .github/scripts/v2-lease-watchdog.test.js`.

Regressions cover original lease expiry, commit and CI protection, comments not extending TTL, explicit active state examples, gate/release/merged/blocked/unknown/missing terminal states, ACTIVE→GATE→fresh ACTIVE transitions, and current V2 issue selection.

PR exact-head Repository Policy, FOODEX V2 Isolation, Required CI and test results must all be recorded after GitHub Actions finishes. This note does not claim a green status prematurely.

## Impact and authorization

- Worker coordination only. No code edits to Laravel Backend/API/DB, Dashboard UI/routes, Customer, Driver or Van.
- No change to public endpoints, role/store/channel authorization, B2B VAN ONLY / B2C DRIVER ONLY, AR/EN strings, RTL/LTR or user-visible screen behavior.
- No release artifacts, credentials, migrations, `main` commits or production changes.
- Scheduled watcher still **not operational** until a separately authorized default-branch dispatcher or external runner; #2931 and #2503 remain OPEN.
