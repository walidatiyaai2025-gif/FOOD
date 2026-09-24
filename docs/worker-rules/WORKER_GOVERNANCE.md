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
