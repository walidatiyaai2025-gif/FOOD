# FOODEX V2 — OWNER-LOCKED ISOLATION & DELIVERY CONTRACT
Date: 2026-11-10 | Parent: #2503 | Integration: `Foodex_V2`

## Non-negotiable rules
1. **No V2 → main integration without a new explicit written owner instruction.** No main PR, cherry-pick, push, merge, tag, deployment, release or version promotion derived from V2 is authorized.
2. Every V2 Issue title MUST start with `V2`; create one issue-scoped implementation branch `feat/<issue>-...` or equivalent, and **base the PR on `Foodex_V2` only**. Before merging, verify `base.ref == Foodex_V2` and the latest exact-head required checks are GREEN. Any PR targeting `main` is invalid regardless of CI color.
3. `Foodex_V2` is an **owner-requested exception to ordinary issue-scoped branch naming**; only this permanent integration branch uses the exception. Do not create another V2 integration branch.
4. No direct feature commits to `Foodex_V2`; merge through reviewed PRs and preserve issue/PR/CI evidence. Do not use automatic merge, promotion or sync into main.
5. Once integrated into `Foodex_V2`, keep the issue open until feature acceptance, all relevant app impacts and tests are complete and evidenced. **#2503 stays OPEN** until all requirements are closed and a final comprehensive audit passes on the exact isolated HEAD.
6. Preserve current production behavior and tenant contracts: B2B=VAN ONLY, B2C=DRIVER ONLY, store-scoped RBAC, secure identity lifecycle, finance maker/checker, audit and invariants.
7. V2 implementations must classify API/route/permission/role/navigation/user-status/finance/dispatch changes for **Customer, Driver, Van individually**. Create a `V2 | MOBILE ...` Issue for each actual affected app and link it to the originating backend issue. A claimed no-impact requires written evidence and contract regression checks. Do not postpone API or mobile parity until merge to main.
8. UI routes must be server-authorized. Sidebar visibility MUST be a projection of true permission/tenant/module/entitlement/readiness decisions; no independent visibility security policy. Platform Owner does not evade authentication, tenant or financial hard constraints.
9. Arabic/English, RTL/LTR, accessible desktop/mobile web, tests, storage/queue/cache/notifications, failure paths and security reviews are required for impacted surfaces.
10. Laravel Setup ZIP & Admin Update ZIP: one-ZIP install, web installer, Super Admin updater, authenticated/signed source, SHA-256, backups, migrations and recovery; exact packages must pass fresh-install and supported-upgrade MySQL CI. All three Android apps must build from same final V2 SHA. Produce **isolated staging artifacts**, never publish production tags or install on live cPanel without owner release order.
11. If a workflow, branch protection or repository settings allow a main merge, this **does not constitute owner authorization**. A dedicated GitHub ruleset requiring owner approval for `Foodex_V2` → `main` must be configured separately by a maintainer; a branch-contained workflow alone cannot fully enforce a main-base server-side ban.
12. No direct changes to original main policies or production artifacts to establish this V2 program.

## Mandatory V2 Issue template
- Source document section/paragraph/table row or sidebar line identifier.
- Exact actor/persona, entry point, data and permission scope, role/module/entitlement/readiness guard.
- Reuse → Extend → Compose → New Screen rationale and correct menu location.
- Backend/API, Dashboard, Customer App, Driver App and Van App impact matrix: each `affected` has a linked issue, or evidence-backed `not affected`.
- Definition of Done, AR/EN, RTL, accessibility, tests (functional, negative, cross-tenant, roles) and CI IDs.
- Implementation branch/PR targeting `Foodex_V2` + exact commit SHA on merge; NO production deployment claim.

## Required GitHub gates
- `FOODEX V2 Isolation Gate` applies to PRs targeting `Foodex_V2`.
- Existing `Repository Policy`, `Required CI Gate`, `Release Validation` and domain/mobile test workflows must run green on latest head where applicable.
- Before integration, review proposed PR base/head in GitHub; reject if base is `main` or if PR introduces production release tags/automatic main sync.
- The final V2 promotion protocol (only if owner later authorizes): frozen exact source SHA → independent review, exhaustive acceptance → formally approved PR to main → protected main required checks → published signed release → cPanel production verification. This protocol is **not authorized by current instruction**.

## Progress and traceability
Master #2503 is only a coordinator. Each leaf Issue owns source requirement acceptance, one branch, one PR. Maintain a source-line-to-Issue manifest on this branch and never mark a missing reference as completed.

## V2 worker lease TTL — automatically released after 30 minutes without work

- Every V2 worker MUST claim an unowned Issue using a fresh public Issue comment with `<!-- foodex-worker-state:v1 -->` and fields `STATE: ACTIVE`, `OWNER: <github-login>`, `BRANCH: <existing issue branch or planned issue-scoped name>`, `HEARTBEAT: <UTC ISO 8601>`. Add label **`worker:active`** as part of the claim; this label makes the Issue eligible for the fast V2 sweeper. Verify no other valid claim or overlapping file-owner exists immediately before posting.
- A **30-minute idle TTL** is measured from the claim or the most recent independently verifiable commit on the owned branch/PR. **Comments, idle status pings, and self-declared heartbeat updates alone do NOT prove work** and do not reset the 30-minute clock. An active exact-head CI run keeps the lease safe while the CI job is genuinely running. Explicit production/human blockers must not be mistaken for permission to deploy.
- When 30 minutes elapse without verified activity, V2 watchdog removes active/waiting labels, assigns **`worker:handoff-ready`**, records a release comment, and makes the Issue free for another worker to claim. The original branch and PR remain intact: successor MUST continue them rather than creating duplicates.
- A worker who returns after an automatic release must stop until they revalidate ownership; if another worker has claimed the Issue, they must hand over. A superseding claim always wins.
- **Operational activation:** staged code `.github/scripts/v2-lease-watchdog.js` and workflow `.github/workflows/v2-idle-lease-watchdog.yml` request a sweep every **5 minutes**. GitHub only runs scheduled workflows defined on the **default branch**. Since owner has expressly forbidden modifying/promotion to `main`, this scheduled watcher is NOT YET AUTOMATICALLY RUNNING and must not be described as enabled. Future approval of a narrowly scoped default-branch dispatcher or a separate external 5-minute runner is required to enable unattended execution; no V2 feature changes may go to main under the present authority.
- The general repository watchdog on `main` runs hourly and its behavior is not an exact 30-minute V2 lease-enforcement guarantee. Until V2 watcher activation, workers must also manually revalidate leases before taking over and before every meaningful push/PR.
