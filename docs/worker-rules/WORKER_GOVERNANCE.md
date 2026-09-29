# Worker Governance

**One Task = One Owner = One Branch.**

- `main` is the only permanent branch.
- Direct pushes to main are forbidden for normal work.
- Every task/feature/fix starts with a GitHub Issue.
- Every Issue has one short-lived branch.
- Only one active worker owns an issue/branch at a time.
- Before each push, re-check issue ownership and lease state.
- Shared high-risk foundation files require dependency coordination.
- PR checks and acceptance criteria must pass before squash merge.
- The feature branch is deleted after merge.
- Product feature development remains blocked until bootstrap readiness is YES.

High-risk shared files include auth config, central routing, core migrations, root package files, app bootstrap, central permission definitions and OpenAPI.

## Scheduled execution contract

Any FOODEX feature that exposes scheduling is incomplete unless its execution path is delivered with it.

Required for every scheduled feature:
- the Laravel Scheduler owns due-work execution;
- first install automatically provisions the operating-system scheduler when the host permits it;
- every system update re-validates/re-provisions scheduler execution;
- the application-level scheduler heartbeat remains enabled as the restricted-host fallback;
- the heartbeat periodically retries managed-cron provisioning, so an existing installation self-heals without operator intervention;
- recovery-critical scheduler runtime files are force-included in every incremental update, even when they are unchanged from the cumulative base;
- scheduling must have health/operational evidence and regression coverage;
- workers must not ship a UI/database schedule that requires an operator to add cron manually;
- release CI must fail if the autonomous scheduling runtime is missing from an update package.

This is a platform invariant, not a per-feature optional task. A schedule that can be configured but cannot execute autonomously is considered incomplete.
