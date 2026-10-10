# FOODEX Project Context

This file gives a coding agent the minimum durable context needed to work safely in FOODEX. It is an orientation layer, not a replacement for `AGENTS.md`, live GitHub state, or authoritative contracts under `docs/`.

## Product

FOODEX is one commerce platform in one monorepo:

- Laravel 12 backend, REST API and role-based Management Dashboard.
- Flutter Customer app for B2C Guest, B2C Customer and approved B2B Customer.
- Flutter Driver app for B2C_DRIVER and B2B_DRIVER with strict role separation.
- Flutter Van app for field/van operations.
- MySQL/MariaDB production data.
- Redis for cache/queues.
- REST API v1 under `/api/v1`; OpenAPI is the API contract source.

## Source-of-truth order

Always resolve conflicts in this order:

1. Live GitHub Issue / branch / PR / exact-head CI.
2. Root `AGENTS.md`.
3. Mandatory contracts and plans under `docs/`.
4. Current source code and automated tests.
5. Files under `.ai/`.

`.ai/CURRENT_STATE.md` is a dated orientation snapshot only. Never use it to decide that an Issue is open/closed, a branch is current, CI is green, or a mission is complete without checking GitHub.

## Non-negotiable project invariants

- One Task = One Owner = One Branch = One PR.
- Continue an existing Issue/branch/PR; never create a retry/replacement branch because a worker or chat stopped.
- No direct implementation push to `main`.
- Red CI is work on the same branch, not permission to bypass or duplicate.
- User-visible functionality must be reachable through normal product navigation/actions; API-only or orphaned UI does not count as product completion.
- Arabic/English parity and RTL/LTR behavior are product requirements.
- Dashboard UI must follow `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`.
- Localization must follow `docs/quality/LOCALIZATION_CONTRACT.md`.
- Release work must follow `docs/release/RELEASE_ARTIFACT_CONTRACT.md`.
- Closed Issues or green generic CI alone do not prove visual/product compliance.
- Customer, Driver and Van are first-class applications; shared changes require parity evaluation.
- Business users must not type internal IDs/raw JSON when an authoritative Lookup/Enum/Builder is appropriate.
- Remember-me / biometric flows must never persist plaintext passwords.

## Standard agent bootstrap

Before coding:

1. Read `AGENTS.md`.
2. Inspect the target Issue and latest comments.
3. Search for an existing branch and PR for the exact scope.
4. Inspect recent merged work in the same area.
5. Check parent mission/dependencies and file ownership.
6. Read this file, `ARCHITECTURE.md`, `CONVENTIONS.md`, `CURRENT_STATE.md`.
7. Read exactly the relevant `.ai/skills/*.md`.
8. Read the authoritative domain contract(s) referenced by that skill.
9. Reproduce/understand current behavior before editing.
10. Make the smallest coherent change and leave tests/evidence another worker can rerun.

## Useful project entry points

- Governance: `AGENTS.md`
- Product overview: `README.md`
- Backend: `backend/`
- Backend routes: `backend/routes/`
- Customer app: `apps/customer_app/`
- Driver app: `apps/driver_app/`
- Van app: `apps/van_app/`
- Release artifacts: `Release/`
- Execution plans: `docs/execution/`
- Quality contracts: `docs/quality/`
- Design contracts: `docs/design-reference/`
- Release contracts: `docs/release/`
- Scripts/automation: `scripts/`, `.github/workflows/`

## How this layer learns over time

Promote only durable knowledge:

- new architectural boundary -> `ARCHITECTURE.md`;
- deliberate technical/product decision -> `DECISIONS.md`;
- repeatable coding/review rule -> `CONVENTIONS.md`;
- recurring hazard or unresolved structural risk -> `KNOWN_ISSUES.md`;
- reusable task method -> relevant file in `.ai/skills/`;
- current orientation snapshot -> `CURRENT_STATE.md`.

Transient status belongs in GitHub Issue/PR comments and exact-head CI, not here.
