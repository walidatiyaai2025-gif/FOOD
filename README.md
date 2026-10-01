# FOODEX

FOODEX is one commerce platform in one monorepo:

- Laravel 12 backend/API plus one role-based Management Web Dashboard.
- One Flutter Customer App for iOS + Android: B2C Guest, B2C Customer, approved B2B Customer.
- One Flutter Driver App for iOS + Android: B2C_DRIVER and B2B_DRIVER with strict separation.
- MySQL/MariaDB for production data and Redis for cache/queues.
- REST API v1 under /api/v1 with OpenAPI as the contract source.

Product implementation and the PH-05 FOODEX brand rollout are present. Usable-product acceptance and release evidence are tracked in Wave I of the product plan; merged features alone do not certify production readiness.

The repository was initially empty, so GitHub required one minimal seed commit on main before an issue branch could exist. Commit 0a918d3dda373390d1962a8c049056737ffc1108 is that one-time seed exception. All substantive work starts on an Issue branch and enters main through Pull Request.

## Worker coordination

All workers, coordinators and coding agents must follow the repository-wide policy in [`AGENTS.md`](AGENTS.md).

The policy applies by default without needing to be repeated in a prompt. In particular:

- One Task = One Owner = One Branch = One PR.
- Existing branches/PRs must be continued instead of duplicated.
- A stale/hung worker is replaced by taking over the same Issue/branch/PR.
- GitHub Issue/branch/PR/CI state is authoritative; chat history is not.
- Parallel workers must use explicit non-overlapping scope ownership.
- Required CI stays owned through green/merge unless there is a genuine external blocker.

`FOOD AUTO-HANDOFF` and `FOOD #<issue> AUTO-HANDOFF` are shorthand for that policy, but the policy applies even when the shorthand is not present.

## Product execution

The authoritative post-foundation implementation queue is in [`docs/PRODUCT_COMPLETION_PLAN.md`](docs/PRODUCT_COMPLETION_PLAN.md). Workers and the Coordinator must use that plan to instantiate atomic Issues, respect dependencies, and measure Product completion separately from Foundation completion.
