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

A worker MUST leave a repository-visible checkpoint at least every **10 minutes** while actively working. A checkpoint may be any of:

- pushing a coherent commit/checkpoint;
- updating/opening the PR;
- adding/updating a machine-readable `foodex-worker-state:v1` block with a fresh `HEARTBEAT`;
- producing a workflow/CI run tied to the branch.

The 10-minute checkpoint SLA exists so a chat/session failure cannot hide a large amount of unpushed work. The 30-minute stale timeout is a takeover detector, not a recommended checkpoint interval.

Before any long wait, tool-heavy operation, CI wait, or potentially fragile session step, checkpoint first when safe.

### Stale lease

A lease is considered stale when there has been **no execution activity for 30 minutes**, there is no currently running CI/action clearly associated with that worker's latest branch head, **and there is no red repository state**.

For lease freshness, execution activity means only:
- a new commit/current branch-head movement;
- a fresh machine-readable `HEARTBEAT` in `foodex-worker-state:v1`;
- running/queued CI on the exact current head.

Ordinary Issue comments, coordinator checkpoints, "please continue" messages, label changes, review chatter, and PR metadata timestamps **do not renew the lease**.

The 30-minute timer applies only to silent abandonment. It does **not** apply to actionable red state.

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

**Red CI is immediate takeover-eligible.** If the latest branch head has a failed, timed-out, cancelled, action-required, startup-failed, or stale CI result, do not wait for the 30-minute stale lease timeout. Mark/treat the task as `CI_FIX` / `worker:handoff-ready` and continue the same Issue/branch/PR immediately.

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

If a worker/session hangs, another worker should take over the same task using the same branch/PR.

If the repository owner explicitly sends `HANDOFF`, `AUTO-HANDOFF`, or otherwise states that the previous chat/worker is interrupted, that is an **immediate lease transfer**. Do not wait for the 30-minute stale timeout. The replacement worker must inspect GitHub first, reuse the existing Issue/branch/PR/head, and the previous worker must stop if it later returns.

---

## 12. Default AUTO-HANDOFF semantics

The phrases:

- `HANDOFF`
- `AUTO-HANDOFF`
- `FOOD AUTO-HANDOFF`
- `HANDOFF #<issue>`
- `FOOD #<issue> AUTO-HANDOFF`
- `FOOD #<umbrella> AUTO-HANDOFF`

are shorthand for this policy across the entire repository.

When the numeric target is a coordination/umbrella Issue, `FOOD #<umbrella> AUTO-HANDOFF` activates **Umbrella Mission / Drain Mode** defined below. It is not a one-lane command.

**Bare `HANDOFF` is intentionally sufficient.** The user does not need to repeat the Issue, branch, PR, SHA, CI status, or previous prompt.

When `HANDOFF` is received, the replacement worker must reconstruct the task from GitHub authoritative state. Selection order is:

1. the Issue/branch/PR explicitly associated with the current conversation, when available;
2. otherwise an existing `worker:handoff-ready` task, preferring the most recently updated resumable handoff;
3. otherwise a task whose latest machine-readable state is `HANDOFF`;
4. never steal a fresh non-stale `worker:active` lease merely because multiple workers exist.

If an explicit repository-owner `HANDOFF` identifies the interrupted work through current conversation context, takeover is immediate and does not wait for stale detection.

No replacement branch or duplicate PR may be created just because the prior chat disconnected.

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

Red state bypasses stale detection entirely. Any current-head red CI result or merge conflict/repository merge blocker is `worker:handoff-ready` immediately, even if the current heartbeat is fresh. A newer rerun of the same workflow replaces an older failed run; old red history must not keep a healthy rerun red.

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


---

## 19. Minimal user command contract

The normal recovery command for an interrupted worker is simply:

```text
HANDOFF
```

That command means:

- previous chat/session may be treated as interrupted;
- GitHub is authoritative;
- inspect current Issue/branch/PR/head/CI before editing;
- continue existing work in place;
- do not ask the user to reconstruct prior chat state;
- do not create a new branch/PR when an existing one belongs to the task;
- fix repository-local failures and drive CI to completion;
- merge/close when permitted;
- continue draining repository-local managed work when the task completes;
- stop only at a genuine external/human gate or when no safe actionable managed work remains.

The user may still provide `HANDOFF #<issue>` when they want to force a specific task, but the longer AUTO-HANDOFF prompt is no longer required.


---

## 20. Umbrella AUTO-HANDOFF Mission / Drain Mode

The command:

```text
FOOD #<umbrella> AUTO-HANDOFF
```

is a persistent **mission command**, not a request to perform one child task and stop.

### Mission objective

Drain the named umbrella Issue to its own completion criteria in the shortest safe time possible, while preserving:

- One Task = One Owner = One Branch = One PR;
- existing branch/PR ownership;
- required CI;
- file/scope ownership fences;
- real external/human gates;
- no fabricated production/deployment/device evidence.

A coordination-only umbrella must **never** get an implementation branch merely because Mission Mode is active.

### Build the authoritative mission queue

At the start of every cycle, reconstruct the queue from current GitHub state, not chat history.

Include only work that belongs to the named umbrella, using:

1. explicit child/checklist Issue references in the umbrella body;
2. GitHub sub-issues, when present;
3. Issues/PRs explicitly declaring `Parent: #<umbrella>`;
4. existing Issues explicitly named by the umbrella completion criteria.

Do not broaden Mission Mode into unrelated repository work.

For every required lane, determine:

- Issue state;
- current branch;
- current PR;
- latest head SHA;
- latest worker-state/handoff;
- lease freshness;
- CI state/result;
- mergeability/conflicts;
- dependency blockers;
- human/external gates.

### Mission lane states

Classify each required lane as one of:

- `COMPLETE` — child acceptance is satisfied and the Issue is closed/merged as required;
- `MERGE_READY` — implementation is complete, required CI is green, and merge can proceed;
- `TAKEOVER` — explicit handoff or stale lease on existing work;
- `CI_FIX` — repository-local red CI/test/lint/build failure; this is immediate takeover-eligible and overrides lease freshness;
- `READY` — actionable and unclaimed;
- `WAITING_CI` — valid CI is actively running on the latest head;
- `ACTIVE_PEER` — a fresh valid lease is owned by another worker;
- `BLOCKED_DEP` — blocked only by another required umbrella lane;
- `HUMAN_GATE` — genuine deployment/production/credential/device/approval intervention.

### Fastest-safe scheduling

To minimize umbrella completion time:

1. unblock dependency-critical lanes first when they block multiple other lanes;
2. close `MERGE_READY` lanes immediately;
3. take over `TAKEOVER` lanes using the same Issue/branch/PR;
4. fix `CI_FIX` lanes on their existing branch/PR;
5. claim `READY` lanes;
6. never steal a fresh `ACTIVE_PEER` lease **unless that lane is currently red/CI_FIX or merge-conflicted; red state overrides lease freshness**;
7. never duplicate a branch/PR just to increase parallelism.

Parallelism is encouraged only across file/scope-disjoint lanes.

If multiple worker chats receive the same umbrella command, each worker must re-run preflight immediately before claiming work and skip lanes that gained a fresh claim. This makes repeated identical commands self-distribute across available lanes instead of duplicating implementation.

After posting a claim, re-fetch the Issue's latest comments/PR state before the first code edit. If another valid claim won the race, release the lane and select another.

### Continuous drain loop

A Mission Mode worker does **not** stop after its first child Issue/PR completes.

After every merge/closure:

1. return to the umbrella;
2. refresh all required lane states;
3. update the umbrella progress checkpoint;
4. select the next highest-priority safe lane;
5. claim/take over it;
6. continue.

Repeat until the umbrella itself reaches one of the terminal states below.

Waiting for CI is not a reason to abandon the mission. Record `WAITING_CI`, then inspect other independent umbrella lanes that can progress safely. Return to the CI lane when its result is available.

### Umbrella progress checkpoint

Keep a concise coordinator checkpoint on the umbrella whenever the queue materially changes, including:

- complete lanes;
- active lanes;
- handoff/takeover lanes;
- CI failures/waits;
- dependency blockers;
- human gates;
- next actionable lane(s).

The checkpoint exists so a replacement chat can continue the mission from GitHub only. Umbrella/coordinator checkpoints are informational and must never be treated as worker lease heartbeats unless they contain an explicit machine-readable `HEARTBEAT` state block.

### Mission terminal states

Mission Mode ends only when one of these is true:

#### COMPLETE

All requirements in the umbrella's own Completion section are satisfied.

Then:

- verify required child Issues are closed/merged as specified;
- verify any explicitly required existing Issue residual is resolved or separated exactly as allowed by the umbrella;
- add final evidence/checkpoint;
- close the umbrella Issue if it is still open.

#### HUMAN-GATED

No repository-local action remains and every incomplete required lane is blocked by a genuine external/human gate.

Then:

- leave exact `BLOCKED_EXTERNAL` state on affected lane(s);
- leave a consolidated umbrella checkpoint stating precisely what human action is required;
- do not fabricate completion;
- do not close the umbrella unless its own completion text explicitly permits that separation.

A worker must **not** stop merely because:

- one lane merged;
- one PR is waiting for CI;
- another lane has red CI;
- a merge conflict exists;
- the previous worker/chat disconnected;
- another independent umbrella lane is still actionable.

### Exact #589 behavior

For the current umbrella `#589`, the command:

```text
FOOD #589 AUTO-HANDOFF
```

means: keep draining #589's required lanes and their existing Issue/branch/PR state until #589's own Completion section is satisfied. Do not create an implementation branch for #589 itself.
