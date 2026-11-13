# V2 #3377 — one-issue / one-branch CI guard

- Parent governance: #2742; source: `AGENTS.md` §1–3 and `docs/v2/V2_ISOLATION_GOVERNANCE.md` mandatory V2 worker claims.
- Verified independent source gap: an active V2 issue (#3371, 2026-11-13) had two different issue-scoped Git refs. The existing V2 isolation gate validated branch syntax but did not compare remote names for the same issue.
- Scope: a **read-only PR-time gate** only for `Foodex_V2`; it lists every remote branch page under the same repository, derives the numeric issue from the PR head, verifies the PR body references that issue, and refuses a conflicting issue-scoped name.
- Any incomplete/invalid API response, missing head branch or forked provenance fails closed. No deletion, branch updates, release/tag, main/production or account access. An active owner resolves conflicting work using AGENTS.md; the CI guard never chooses a winner.
- Unit regressions include one matching branch PASS; second branch FAIL; cross-issue parallel branches PASS; other non-issue refs ignored; missing head FAIL; malformed name/payload FAIL; page-2 conflict FAIL; API throw FAIL; mismatched body/base/fork FAIL.
- Execution: `node --test .github/scripts/v2-issue-branch-uniqueness.test.js`. GitHub `FOODEX V2 Isolation Gate` runs the same unit regressions followed by the GitHub read-only check on the PR.
- This guard is a **PR-time detection layer**, not an atomic GitHub branch-creation lock. Concurrent creation after the last check requires a fresh check; source ownership comments and worker leases remain mandatory. Never describe the check as a replacement for lease coordination.
- Impact matrix: Backend no runtime/API change; Dashboard no route/UI/security change; Customer no Flutter/public API change; Driver no Flutter/public API change; Van no Flutter/public API change. No new translation strings or UI/RTL behavior. AR/EN unaffected.
- Acceptance: exact PR head Repository Policy / FOODEX V2 Isolation Gate / FOODEX Required CI Gate SUCCESS, verified `base=Foodex_V2`, review, merge SHA and branch cleanup. Only this atomic issue may close; #2742/#2503 retain broader source acceptance.
