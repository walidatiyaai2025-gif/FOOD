# FOODEX Worker Coordination Policy

This file is the repository-level default operating policy for **all workers, coordinators and coding agents** working in this repository.

It applies automatically. A prompt does **not** need to repeat these rules.

The objective is simple:

> **No duplicate work, no unnecessary branches, and any interrupted worker must be replaceable immediately from GitHub state.**

GitHub is the source of truth. Chat history is not.

---

## 1. Mandatory rule: One Task = One Owner = One Branch = One PR

For every implementation unit:

- one atomic Issue owns the scope;
- one active worker owns it at a time;
- one implementation branch is used;
- one PR is used;
- a replacement worker continues the same Issue/branch/PR.

Never create a second branch or replacement PR merely because:
- another worker stopped responding;
- a chat became too long;
- CI failed;
- the original worker disconnected;
- work needs to be resumed from another session.

If the branch or PR already exists, **continue it**.

---

## 2. Required preflight before any code change

Before creating a branch, editing code, or opening a PR, the worker MUST inspect:

1. the target Issue and its latest comments;
2. open PRs that reference the Issue or exact scope;
3. existing branches for the Issue/scope;
4. recent merged PRs touching the same scope;
5. parent/umbrella Issue dependencies and ownership fences.

Then choose exactly one path:

### A. Existing active branch/PR exists
Continue it. Do not create anything new.

### B. Existing branch/PR exists but the previous worker is stale
Take over the **same** branch and PR using the stale-handoff rules below.

### C. Work is already merged
Do not reimplement it. Record that the scope is already complete and move to the next valid task.

### D. No branch/PR exists
Claim the Issue, then create exactly one branch.

### E. No atomic Issue exists
Create an atomic Issue first. Do not implement directly on an umbrella Issue unless that umbrella explicitly says it is an implementation unit.

---

## 3. Claim / lease protocol

Before editing, add a claim comment to the Issue containing:

- Owner / worker identity or role;
- exact branch name;
- exact scope / files or subsystem owned;
- current UTC or Kuwait-time timestamp;
- intended next milestone (for example: tests, PR, CI fix).

A claim is a **lease**, not permanent ownership.

### Lease heartbeat

A worker should renew visible activity at least every **20 minutes** while actively working by one of:

- pushing a commit;
- updating/opening the PR;
- adding an Issue/PR progress comment;
- producing a workflow/CI run tied to the branch.

### Stale lease

A lease is considered stale when there has been **no repository-visible activity for 30 minutes** and there is no currently running CI/action clearly associated with that worker's latest branch head.

A replacement worker does not need to wait for the old chat/session.

The replacement worker must:

1. comment on the same Issue that the stale lease is being taken over;
2. state the existing branch and PR;
3. continue from the latest remote branch head;
4. preserve existing commits/history;
5. avoid creating a replacement branch or PR.

If the old worker returns after takeover, it must stop and defer to the current Issue lease owner.

---

## 4. Handoff protocol

When pausing, blocked, or leaving work unfinished, write a handoff comment in the Issue or PR with:

- Issue number;
- branch;
- PR number, if open;
- latest head SHA;
- what is complete;
- what remains;
- current CI status;
- exact failing job/test if any;
- next concrete action;
- any external blocker that cannot be solved from the repository.

A new worker should be able to resume using only GitHub state.

**No important execution state may exist only inside a chat.**

---

## 5. Branch policy

Branch names should be Issue-scoped and stable, for example:

- `feat/592-b2b-auth-return-contract`
- `fix/512-live-map-production-reliability`
- `chore/601-worker-auto-handoff-policy`

Rules:

- do not open "v2", "retry", "new", "final", "fixed-again" branches for the same Issue;
- do not fork a new branch because CI is red;
- do not fork a new branch because the original worker hung;
- do not create backup branches unless explicitly requested for recovery;
- start from latest `main` only when no valid existing task branch exists.

If an Issue already specifies a branch name, use that exact branch.

---

## 6. PR policy

- One implementation PR per atomic Issue.
- If a PR exists, fix it in place.
- Red CI is work to continue on the same branch, not a reason to open a new PR.
- Rebase/update the existing branch when required.
- Do not merge until required checks are green unless the repository owner explicitly authorizes an exception.
- Keep the PR body and Issue comments updated enough for another worker to resume.

The PR must identify:

- Issue;
- owner;
- branch;
- current lease/handoff state;
- test evidence;
- known blockers.

---

## 7. Parallel worker policy

Parallel work is encouraged only when ownership is file/scope-disjoint.

Before starting parallel lanes:

- define exact Issue ownership;
- define file/subsystem boundaries;
- identify shared files likely to conflict;
- assign shared infrastructure to one lane only.

If two lanes need the same shared file:

1. one lane owns the file first;
2. the other lane waits for that change to merge;
3. the second lane rebases and adds only its remaining delta.

Do not have two workers independently implement the same shared change.

---

## 8. Umbrella Issue policy

Umbrella Issues coordinate work; they are not implementation branches unless explicitly stated.

For an umbrella:

- split work into atomic child Issues or clearly defined existing Issues;
- each lane gets one owner/branch/PR;
- workers must check whether another lane already owns the files they need;
- completion of one child does not justify editing another child's scope.

If an umbrella says to continue an older existing Issue, continue that Issue rather than creating a duplicate child.

---

## 9. CI ownership

The worker who owns the Issue owns its CI to completion.

That worker must:

- inspect failing jobs;
- fix failures on the same branch;
- push updates;
- rerun/observe checks as appropriate;
- continue until green, merged, or genuinely externally blocked.

Do not abandon a PR simply because CI is red.

A helper worker may take over CI only by following the same lease/handoff protocol and using the same branch/PR.

---

## 10. External blockers vs repository blockers

A worker should solve repository-local blockers independently.

Examples that are **not** reasons to stop and ask for human intervention:

- test failures;
- lint failures;
- merge conflicts that can be safely resolved;
- stale generated files;
- missing test coverage;
- documentation drift;
- an existing branch that needs continuation.

Examples that may require an external blocker note:

- unavailable production server access;
- unavailable device required for acceptance;
- missing third-party credentials;
- approval needed for a production toggle;
- real-world evidence that cannot be fabricated from CI.

Never invent production evidence from tests or CI.

---

## 11. Chat/session failure policy

A chat or worker session may disappear at any time.

Therefore:

- repository state must remain resumable;
- do not rely on private chat context for critical next steps;
- commit coherent progress before long waits when safe;
- record blockers/next actions in GitHub;
- a new worker should be able to resume without asking the user to reconstruct history.

If a worker/session hangs, another worker should take over the same task after the stale-lease check, using the same branch/PR.

---

## 12. Default AUTO-HANDOFF semantics

The phrases:

- `FOOD AUTO-HANDOFF`
- `FOOD #<issue> AUTO-HANDOFF`
- `FOOD #<umbrella> AUTO-HANDOFF`

are shorthand for this policy.

But this policy applies even when those phrases are **not** present.

An AUTO-HANDOFF worker must:

1. inspect GitHub state;
2. select or resume the correct non-conflicting task;
3. claim/take over the existing lease;
4. reuse existing Issue/branch/PR where present;
5. work through CI;
6. merge/close when permitted and complete;
7. otherwise leave a complete GitHub handoff.

---

## 13. Definition of a clean completion

A task is cleanly complete when:

- implementation matches the Issue acceptance criteria;
- tests/required CI are green;
- PR is merged;
- Issue is closed or updated with the remaining external-only gate;
- obsolete task branches are cleaned up when safe;
- no duplicate open PR/branch remains for the same scope;
- handoff/status is visible in GitHub.

---

## 14. Conflict resolution priority

When instructions conflict, use this order:

1. explicit current repository-owner instruction;
2. safety/security requirements;
3. target Issue acceptance criteria;
4. this `AGENTS.md` coordination policy;
5. older Issue comments / historical plans.

Do not use an old worker claim to override a newer explicit takeover or repository-owner instruction.


---

## 15. Machine-readable worker state

Every active implementation Issue/PR should maintain a current machine-readable state block. It may live in the PR body or in the latest Issue progress/handoff comment.

Use exactly this shape:

```text
<!-- foodex-worker-state:v1 -->
STATE: WORKING
OWNER: worker-name-or-role
BRANCH: feat/123-stable-branch-name
PR: #456
HEAD: full-or-short-head-sha
HEARTBEAT: 2026-10-01T06:00:00Z
BLOCKER: none
NEXT_ACTION: next concrete repository action
```

Allowed `STATE` values:

- `WORKING`
- `WAITING_CI`
- `BLOCKED_REPO`
- `BLOCKED_EXTERNAL`
- `READY_TO_MERGE`
- `HANDOFF`

Allowed `BLOCKER` semantics:

- `none` — normal work;
- `ci` — tests/checks/action failure or wait; repository-local;
- `repo` — code/conflict/test/docs/rebase or other repository-local blocker;
- `deploy` — real deployment action is required;
- `production` — production-side verification/evidence/action is required;
- `credentials` — required secret/credential is unavailable;
- `device` — required real device/execution environment is unavailable;
- `approval` — explicit human approval is required;
- `human` — other genuine external intervention.

Do **not** classify CI failures, merge conflicts, test failures, missing code, branch drift, or ordinary debugging as human blockers.

The Worker Watchdog may use this block plus GitHub branch/PR/CI activity to classify the task.

---

## 16. Worker Watchdog and automatic takeover

The repository runs a scheduled/event-driven Worker Watchdog.

It maintains these queue states:

- `worker:ready` — atomic repository-local work with no active claim;
- `worker:active` — a fresh worker lease exists;
- `worker:waiting-ci` — CI/actions are currently running;
- `worker:handoff-ready` — worker lease is stale, CI needs takeover, a merge-ready PR was abandoned, or handoff was explicit;
- `gate:human` — real intervention outside normal repository work is required.

Specific human gates may also be labeled:

- `gate:deploy`
- `gate:production`
- `gate:credentials`
- `gate:device`
- `gate:approval`

The watchdog:

- does not create replacement implementation branches;
- does not create replacement PRs;
- does not bypass required CI;
- does not enable production toggles;
- does not fabricate deployment/production/device evidence.

When a stale task is detected, it marks the existing Issue `worker:handoff-ready` and records the existing branch/PR/head when available.

The next worker must take over that exact work.

---

## 17. Continuous queue drain

Workers should not stop merely because their first Issue merged.

Unless the repository owner explicitly says **only this Issue/task**, after finishing a task a worker must inspect the managed queue and continue with the next safe repository-local item in this order:

1. `worker:handoff-ready` — resume abandoned/stalled existing work first;
2. `worker:ready` — claim new atomic work second.

Before claiming the next item, still perform the full preflight and dependency/ownership checks.

A worker must **not** consume Issues labeled `gate:human` until the external requirement has actually been satisfied.

The desired steady state is:

> no repository-local actionable work left; any remaining managed Issues are blocked only by genuine deployment, production, credential, device, or approval gates.

A worker may stop earlier only when:

- the user explicitly restricted it to one exact task;
- there is no safe actionable Issue;
- all remaining managed work is human-gated;
- an external service/tool required for the next action is unavailable and the Issue has been classified accordingly.

---

## 18. Managed Issue marker

New atomic implementation Issues should include:

```html
<!-- foodex-worker:managed -->
```

This opts the Issue into watchdog queue classification even before a branch/PR exists.

Do not put this marker on coordination-only umbrella Issues unless they are intended to be directly executable.
