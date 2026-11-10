# FOODEX V2 #2929 — 30-minute lease reconciliation (isolated)

Source: Issue #2929; parent #2503. Target **Foodex_V2 only**. Never deploy, push, tag, or open a PR to main without new owner approval.

## Canonical implementation

There is exactly **one** V2 automatic lease reconciler: \`.github/scripts/v2-lease-watchdog.js\`; workflow \`.github/workflows/v2-idle-lease-watchdog.yml\`. The older issue branch's \`v2-lease-reaper.js\` was intentionally not promoted: competing sweepers could race each other and incorrectly release a newly claimed Issue. The original branch/commit history remains intact.

- GitHub Search returns \`data.items\`; REST list endpoints return \`data\` arrays. Both shapes are validated before paging. Failed or truncated API responses abort, not silently undercount.
- Machine-readable original claim timestamp anchors the 30-minute TTL. Self-declared HEARTBEAT/status comments without verifiable code do not renew it. New worker after a real handoff gets a new claim epoch.
- Actual issue-branch commit updates are activity. Queued/in-progress exact-head Actions and check-runs protect the lease.
- Before changing labels, the reconciler verifies latest ownership, live labels, active human gates, a second branch-head SHA read, and a second exact-head CI check. GitHub APIs provide no multi-resource compare-and-swap for Issue comments and labels; race protection is best-effort, **not a strict atomic lock**.
- Explicit human gates are protected. Red completed CI does not permanently reserve a lease. Stale, cooperative fixes must continue the **same Issue, branch and PR**, preserving commits.
- Hand-off writes one deduplicated marker/comment plus label changes, and never deletes branches, closes PRs/issues, or changes production.
- API permission/network failures fail closed; partially succeeded API mutations should be reconciled by the next authorized sweep.

## Verification

Run \`node --test .github/scripts/v2-lease-watchdog.test.js .github/scripts/v2-lease-watchdog.reconciliation.test.js\` and exact-head Repository Policy, FOODEX V2 Isolation and Required CI.

The integration tests simulate search and list API pagination, 29m/30m TTL, heartbeat-only chatter, commit movement, queued CI, human gates, a new worker claim, head-SHA race, and API 403 failure. Dashboard, Backend business flows, Customer, Driver and Van have **no runtime API/Flutter changes**: this is GitHub maintenance automation only.

## Activation limitation

The V2 5-minute cron is staged but **dormant**. GitHub Actions schedule only runs from the default branch; this workflow resides on Foodex_V2 and may not be copied to main without owner permission. ChatGPT Scheduled Automations can run **no more often than hourly**, so they cannot guarantee a 15-minute sweep or exact 30-minute lease expiry. Existing hourly V2 checks are best-effort only. Activation requires a separately authorized external dispatcher or a narrowly approved default-branch dispatcher, neither of which is included in this change.

This Issue's repository deliverable may be accepted on tested code alone, but unattended sub-hourly execution cannot be represented as live until such an external scheduler is actually installed and observed.
