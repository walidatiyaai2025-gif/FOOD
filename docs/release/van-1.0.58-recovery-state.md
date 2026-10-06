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

## Root-cause correction from run 37464756717

- Head `980c74d8ccb8f96890c5d0d186bf8786b9d0107b` used separate pre/post-Finish server lifecycles and still failed the same Field Operations request.
- Laravel log proved the deterministic application defect: `Unclosed '[' does not match ')'` while compiling `backend/resources/views/admin/field-operations.blade.php`.
- The server lifecycle stabilization remains useful, but it is not the remaining blocker.
- Application fix prepared: precompute territory GeoJSON features in a normal Blade PHP block and serialize a simple variable, avoiding the nested closure/array expression inside `@json(...)`.
- The same view also had an incomplete Customer relationship table change: the `Collection context` header existed without a matching cell. The prepared fix renders the canonical `collection_context` already supplied by `VanCustomerCollectionContextService`.
- Because a setup-delivered Blade file changes, the next successful Fresh Setup run must regenerate/publish the Setup ZIP/artifacts before final cleanup.
- Next action: validate the application-fix checkpoint end-to-end, then inspect the artifact publication commit before final cleanup.

## Follow-up from run 37465399043

- Head `41ca1672f68cc13cdf3abd17adab0ede1b2386af` removed the original Blade parse error.
- New exact failure: `Undefined variable $existingTerritoryFeatures` on the Territories page.
- Interpretation: the complex nested expression is no longer a syntax blocker, but the intermediate Blade PHP variable is not reliable in this rendering path.
- Safer fix prepared: remove the intermediate variable entirely and emit the existing GeoJSON features through Blade `@foreach` loops with simple scalar/object `@json` expressions.
- Customer Collection context rendering from the same checkpoint remains in place.
- Next action: validate the simplified Territories rendering on a new exact-head Fresh Setup run.

## Follow-up from run 37465917249

- Head `4c53fd7b7e63d29825393c59659eada90f5dae39` passed the previous Field Operations/Territories rendering blockers and progressed through populated Van/Customer/Territory flows.
- Exact current failure: the Address Quality page check searched for literal `Details & history`, while Blade correctly HTML-escapes the ampersand as `Details &amp; history`.
- Classification: deterministic test-harness assertion defect, not an application defect.
- Harness fix prepared: assert the escaped HTML text.
- Artifact publication guard correction: determine setup-delivered changes relative to the `source_commit` stored in the committed `Release/BUILD_INFO.json`, not only relative to the immediate parent. This preserves required Setup ZIP publication when an application fix is followed by a CI-only recovery commit, while still allowing final cleanup commits to avoid unnecessary artifact churn.
- Next action: require full Fresh Setup GREEN; because backend changed since published BUILD_INFO source `72acda6fef92b04d2dba522cbd647f1ee2f1d024`, the successful run must publish regenerated release artifacts.

## Follow-up from run 37466514029

- Head `34dd2508224b98d043d41901c757ad254ddacb97` passed all fresh-install HTTP/API/Field Operations checks through the visual stage.
- Exact failure: Node ESM could not resolve `playwright` because the capture module was created under `/tmp` while `playwright` was installed under the GitHub workspace.
- Classification: deterministic test-harness module-resolution defect; application behavior had already passed up to visual capture.
- Harness fix prepared: create and execute the temporary capture module inside `$GITHUB_WORKSPACE`, where Node can resolve the installed workspace `node_modules`, then remove it after capture.
- Next action: require the full visual/RTL-LTR acceptance and artifact publication to pass on the next exact head.
