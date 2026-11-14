# V2 #3453 — Exact-head CI pagination before idle-lease release

**Source:** FOODEX `AGENTS.md` §3 and `docs/v2/V2_ISOLATION_GOVERNANCE.md` lease TTL; parent #2931 (scheduler), master #2503. **Target `Foodex_V2` ONLY.** No `main`, release, tag, deployment or cPanel actions.

## Source-level failure mode

The existing V2 watchdog inspected only the first 100 check runs and first 100 Actions workflow runs returned by GitHub. When an exact-HEAD queued or running job was on a later page, `ciInProgress` could return false and a >30-minute lease could be released while real CI was active. The previously merged #2929 pagination tests covered Issues/Search/PR collections, not the `check_runs` and `workflow_runs` response collections. The #3439 patch addresses already-merged PR ownership, not this hazard.

## Bounded correction

The canonical `listPages` helper now supports named GitHub object collections as well as arrays. `ciInProgress` retrieves all check run and workflow run pages before deciding. Every page uses `per_page: 100`; a malformed/partial response or the bounded 15-page scan limit throws **fail closed**, before any issue-label/comment mutations. It still excludes watchdog self-jobs and counts only exact-head workflow activity; false-positive activity from another SHA must not renew the lease.

## Automated regression acceptance

Command (repository root):

```bash
node --check .github/scripts/v2-lease-watchdog.js
node --test .github/scripts/v2-lease-watchdog.test.js .github/scripts/v2-lease-watchdog.reconciliation.test.js
```

Test matrix: 100 completed first-page Checks with an active second-page Check; 100 completed first-page Workflows with an active exact-head second-page Workflow; unrelated SHA second-page workflow does **not** block legitimate stale release; malformed second page and oversized pagination throw without mutations. Existing 29-minute/30-minute expiry, queued first-page checks, racing claim/commit, merged-PR cleanup, human approval and API-failure guards must remain green. Verify exact-final-HEAD Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI and terminal child jobs on the one issue PR before merging.

## Scope/impact classification

- **GitHub worker coordination:** affected; no new scheduling or permission grant. The 5-minute V2 schedule remains **dormant** while this code is isolated on `Foodex_V2`; #2931 is independently gated.
- **Backend/API:** NOT AFFECTED. No Laravel runtime/route/security token/tenant/finance schema change.
- **Dashboard:** NOT AFFECTED. No admin navigation, route, UI, AR/EN, RTL/LTR or responsiveness change.
- **Customer / Driver / Van:** each NOT AFFECTED. No Flutter source/API payload, offline behavior, permissions, notification, order or dispatch change. B2B=VAN ONLY and B2C=DRIVER ONLY invariant remains unchanged.
- **Release/UAT:** no signed Setup/Update ZIP or APK is built or published by this patch; V2 master #2503 retains final human/staging acceptance.

No worker-owned files other than this Issue's two JS paths are edited.
