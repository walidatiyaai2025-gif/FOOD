# Skill: Implement a Feature

## 1. Establish product contract
Identify user/role, business outcome, navigation entry, data authority, permissions/store/channel scope, API impact, affected apps, success/empty/error/stale states, AR/EN needs and acceptance evidence.

A hidden endpoint or orphaned route is not a complete user-facing feature.

## 2. Respect task lineage
Run the `AGENTS.md` preflight and reuse existing Issue/branch/PR.

## 3. Trace the vertical slice
Map UI -> route -> validation -> authorization -> application/domain service -> persistence/integration -> response -> client state -> tests/evidence.

Prefer existing domain patterns over parallel architecture.

## 4. Cross-surface parity
If a contract is shared, explicitly evaluate Dashboard, Customer, Driver and Van impact. "No change required" is valid only after evaluation.

## 5. Implement coherently
Schema first when needed, then backend contract, UI/client integration, translations, tests, and contracts/docs only when behavior changes them.

No mock/fake-live fallback for a feature claiming production behavior.

## 6. Acceptance
Prove normal journey plus authorization/isolation and relevant failure states. Visual/interaction work must follow the repository requirement-evidence contract.

## 7. Durable learning
If this establishes a reusable architecture/decision, update `.ai/ARCHITECTURE.md` or `.ai/DECISIONS.md`.
