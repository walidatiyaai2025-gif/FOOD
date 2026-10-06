# FOODEX Van 1.0.58 recovery state

- Canonical branch: `release/1.0.58-van-complete`
- Current remote SHA at recovery start: `3103d5d20862bff1ca6963180903a5081cb067cb`
- Recovery checkpoint status: server/fixture harness fix is committed together with this ledger; always fetch the branch head before resuming.
- Latest failing workflow: FOODEX Van 1.0.58 Fresh Setup
- Latest failing run ID: `37461912007`
- Failing step: `Validate clean install from ZIP only`
- Exact failure chain observed: transient connection refusals while port 8099 was starting, followed by `KeyError: 'b2b_store_id'`.
- Root cause confirmed in workflow: the fresh fixture created the B2B store and owner but omitted `b2b_store_id` and `owner_id` from `/tmp/foodex-fresh-fixture.json`; the acceptance consumer required both. Server startup also lacked process-liveness/log diagnostics and unnecessarily restarted the same port between pre-finish and post-finish checks.
- Current fix: keep one Laravel acceptance server alive across Finish, verify PID + bounded HTTP readiness, print server/Laravel logs on readiness failure, export/validate the required fixture IDs, and fail descriptively before downstream acceptance.
- Setup ZIP decision: CI/test-harness-only change; do not republish/regenerate canonical release artifacts unless setup-delivered/application inputs changed.
- Next intended action: verify the checkpoint remote SHA, then inspect the Fresh Setup run for that exact SHA. If GREEN, remove this ledger and the temporary workflow recovery marker in one final cleanup checkpoint and require Fresh Setup GREEN again on that final exact head.
- Known passing acceptance evidence from the prior run before the fixture parsing failure: dashboard login/navigation; Van & Field Operations sidebar/pages/empty states; Live Fleet Map surface; territories; Sales Control; Flash Offers/Preview/Analytics; canonical feature flags; commercial/customer/Van routes and services; scheduler; notification routes; account deletion/legal; store readiness.
- Do not merge to `main`.

## Checkpoint update

- Verified checkpoint remote SHA: `4a24f1fe33d162f2f9d42405bcb7e958d4b33019`.
- Fresh Setup run `37463667017`: FAILED after the original port/fixture blockers were cleared; no `KeyError` and no readiness diagnostic fired.
- Current blocker: a later shell assertion/test exits 1 silently inside fresh-install acceptance.
- Next action: phase-safe ERR diagnostics only; do not modify application code until the exact failing shell command is identified.

## Root cause confirmed by run 37464156100

- Exact diagnostic: Field Operations page HTTP assertion failed while the server log printed `Environment modified. Restarting server...`.
- Causal chain: installer Finish changes environment/install state -> long-lived `php artisan serve` detects the change -> Artisan development server hot-restarts during acceptance -> an authenticated Field Operations request lands during restart and returns non-200.
- Recovery fix: use two deterministic server lifecycles. Pre-finish server validates `/install`, then is stopped and port 8099 must be released before Finish. After Finish, start a fresh server and wait for the dashboard login endpoint before any authenticated acceptance calls.
- Application code remains untouched; this is test-harness lifecycle stabilization only.
- Next action: require Fresh Setup GREEN on the new exact remote head before cleanup.
