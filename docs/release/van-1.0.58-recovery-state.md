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
