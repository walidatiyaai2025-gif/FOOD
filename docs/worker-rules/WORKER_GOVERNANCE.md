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

## Application Preview parity contract

For any Customer App or Driver App change that is Dashboard-managed or changes app-visible configuration/state, workers must follow `docs/architecture/APP_PREVIEW_ARCHITECTURE.md`.

Mandatory worker rules:
- do not create Dashboard-only mock copies of Flutter screens;
- reuse the real app/shared runtime and authoritative `/api/v1` contracts;
- preserve StoreContext/channel/role isolation in Preview;
- include Guest/authenticated and B2B/B2C/store variants when the feature affects them;
- update Draft/Published resolution and compatibility handling when configuration changes;
- update API/OpenAPI when the runtime contract changes;
- update Preview parity/regression tests in the same PR;
- treat a Mobile-only or Dashboard-only change that causes preview/runtime drift as incomplete;
- coordinate before editing shared preview auth/session, OpenAPI, migrations, app bootstrap or shared renderer files.

For #498 work, child tracks remain one issue/owner/branch each; no worker may claim an active leased branch owned by another worker.

## Active autonomous FOOD mission

The current active project Mission is **#1001 / UIUX-V42**.

Authoritative execution files:
- `docs/execution/UIUX_V42_AUTONOMOUS_MISSION_PLAN.md`
- `docs/execution/ACTIVE_FOOD_MISSION.json`

Bare owner commands `حرك مشروع FOOD`, `اشتغل على مشروع FOOD`, and `FOOD MISSION` mean: resolve the live open `[MISSION][ACTIVE]` umbrella and apply `AGENTS.md` Mission/Drain rules immediately.

Mission safety rules:
- maximum six active implementation lanes;
- one child Issue, one canonical branch, one PR;
- child Issues record their exact canonical branch and workers must use it;
- before branch/PR creation, re-read GitHub and reuse existing state;
- after connection loss or an uncertain mutation result, re-read GitHub before retrying;
- CI red/conflicts/test failures remain repository work on the same lane;
- current-head running CI is preserved rather than duplicated;
- a worker that completes one lane returns to the umbrella and continues another safe lane;
- #1012 freezes/converges code but does not close the umbrella;
- only terminal real-release child #1021 may close the umbrella after a verified clean Setup build;
- `main` is not a merge/auto-merge target for this Mission.

GitHub is the durable mission state. Chat history is never required for recovery.

