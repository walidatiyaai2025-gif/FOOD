# Skill: Fix a Bug

Use for incorrect behavior, regression, crash, data mismatch or user-visible defect.

## 1. Live preflight
Follow `AGENTS.md` Section 2: target Issue, same-scope PR, existing branch, recent merged work, parent mission/file ownership. Continue the same branch/PR if it exists.

## 2. Reproduce before editing
Capture actor/role, store/channel/app, route/screen/endpoint, minimal data state, expected vs actual behavior, relevant error/log and exact source SHA.

Do not fix from screenshot wording alone when repository/runtime evidence can identify the real path.

## 3. Locate the authority boundary
Classify root cause: UI/navigation, Flutter state, validation, authorization/scope, domain transition, query/data mapping, migration/schema, job/notification, integration or CI/test gate.

Fix the authoritative layer, not a downstream symptom.

## 4. Add regression proof
Prefer a failing automated test before/with the fix. For data/security bugs add isolation cases. For notification/state-transition bugs test deduplication/idempotency where relevant.

## 5. Validate narrowly, then broadly
Run the smallest relevant test first, then formatter/static analysis/changed-area gates. Do not repeatedly rerun unchanged deterministic red CI.

## 6. Handoff
Update Issue/PR with root cause, files changed, tests, remaining risk, exact head SHA/CI and next action.

If the bug exposed a reusable hazard, update `.ai/KNOWN_ISSUES.md`.
