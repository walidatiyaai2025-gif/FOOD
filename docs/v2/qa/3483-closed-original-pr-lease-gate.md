# V2 #3483 — Closed-unmerged original PR worker lease gate

Parent #2931 and master #2503. Target: `Foodex_V2` ONLY.

## Reproduction and source finding

The V2 watchdog already prevents a merged original Issue PR from being advertised as an idle worker takeover, but `run` previously consulted the original PR only to check `merged`. A confirmed **CLOSED / NOT MERGED** original PR (as in owner-directed CLOSE ALL and several historical V2 Issue examples #2813, #3217, #2758) fell through the generic stale lease action and received `worker:handoff-ready`, implicitly suggesting a duplicate branch/PR. #3439 guarded only merged PRs. #3471 guarded terminal state comments, not a closed PR's live GitHub metadata.

## Fix

`isMatchingClosedUnmergedPr` requires a closed PR with explicit `merged=false`, no `merged_at`, target `Foodex_V2`, and the same claimed branch. For that original PR, the staged watchdog preserves its Issue/branch/PR, waits for exact-head CI to finish, verifies the original claim and branch are unchanged on a second fetch, removes obsolete active/waiting labels, adds `gate:approval` and writes a uniquely marked explanation only once. It never marks the Issue `worker:handoff-ready`, reopens or merges the PR, deletes the branch, or closes the Issue.

The generic stale-release path also re-reads the original PR just before mutation, and aborts if an originally open PR has become closed or merged during the scan. The next sweep can then classify it safely. Unknown or contradictory PR data fails closed rather than assuming a safe closed-unmerged state.

## Regression coverage

Run `node --check .github/scripts/v2-lease-watchdog.js` and `node --test .github/scripts/v2-lease-watchdog.test.js .github/scripts/v2-lease-watchdog.reconciliation.test.js`.

Added hermetic full-sweeper tests for a confirmed closed-unmerged original PR, protection while CI is queued/running, changed branch or successor claim, foreign/non-Foodex_V2 closed PR, and administrative PR closure racing a normal lease release. Existing tests continue to exercise normal open-unmerged expiry and merged-original exemption. Exact PR-head Repository Policy, FOODEX V2 Isolation and FOODEX Required CI must all be terminal SUCCESS before integration; this QA note does not claim an unobserved test outcome.

## Impact and security boundaries

Only repository-internal worker coordination Node code/test/docs change. Laravel Backend/API, Dashboard UI, Customer/Driver/Van Flutter are not modified, and no mobile contract/permission/role/tenant/store/finance/AR-EN UI strings/B2B VAN-only/B2C DRIVER-only behavior changes. No release, main, production, live accounts or deployment. Parent #2931 remains OPEN because default-branch 5-minute scheduler activation still requires separate explicit authorization, not contained in this PR.
