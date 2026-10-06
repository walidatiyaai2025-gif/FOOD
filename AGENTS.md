# FOODEX Worker Coordination Policy

This file is the repository-level default operating policy for **all workers, coordinators and coding agents** working in this repository.

It applies automatically. A prompt does **not** need to repeat these rules.

The objective is simple:

> **No duplicate work, no unnecessary branches, and any interrupted worker must be replaceable immediately from GitHub state.**

GitHub is the source of truth. Chat history is not.

## Mandatory Dashboard UI/UX and Master-Data contract

Before implementing or modifying any Dashboard page, Admin navigation, business-facing form, or application-management feature, every worker **MUST** read and follow:

- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`

This is a repository-level Definition-of-Done contract, not optional visual guidance.

Core invariants include:

- Sidebar navigation is organized by business domain; do not hide unrelated features inside one project/epic menu.
- Related page functions use horizontal page-level tabs where appropriate.
- Add/Create actions use clear FOODEX primary buttons and Modal/Drawer/Wizard workflows.
- Manage/Edit/View actions open the exact record directly rather than redirecting to a generic list.
- Dashboard action buttons use FOODEX green with white text; default Bootstrap-looking action buttons are not acceptable.
- Data grids follow the Orders-grid interaction model with useful row data; row actions use one compact FOODEX-green three-dots (ellipsis) menu containing the available actions.
- Business users must never be asked to type internal database IDs, keys, technical codes, or raw JSON when a Lookup, Enum, or Builder is appropriate.
- Every Lookup must read from its authoritative Master Data source/page; do not hard-code managed Master Data into dropdowns.
- Geography/Territories are map-first for business users; raw GeoJSON/keys remain advanced/internal.
- Administration opens as an icon/card Admin Hub rather than a long nested link list.
- Customer, Driver and Van are three first-class FOODEX applications; app-level capabilities require parity evaluation across all three.
- Arabic/English, responsive behavior and shared FOODEX components are acceptance requirements.
- Every page/feature/function must comply with `docs/quality/LOCALIZATION_CONTRACT.md`: selected Arabic must not surface untranslated English system wording/data labels, and selected English must not surface untranslated Arabic system wording/data labels. New translation keys require AR/EN parity; raw status/state/channel/role/type values must be localized before display.
- Customer / Driver / Van mobile layouts are data-first and compact: title + subtitle must consume only a minimal footprint (target ~1% of usable page area), Start/End filters + action stay on one line, order numbers never wrap, screens use the available viewport, and list/grid rows remain compact.
- Customer / Driver / Van must evaluate and implement Remember Me + biometric unlock consistently; never persist plaintext passwords for biometric login.
- The main Dashboard map is unified Live Tracking for both Drivers and Vans, with person markers for Drivers and vehicle markers for Vans.
- Every FOODEX version promotion must follow `docs/release/RELEASE_ARTIFACT_CONTRACT.md`: `Release/Updates` is refreshed and synchronized versioned APKs for Customer + Driver + Van plus `LATEST_RELEASE.json` are generated automatically. A release is incomplete if any one of the three APKs is missing.

A worker must classify every business-facing field before implementation as Lookup, Enum, Builder, legitimate free input, or advanced technical input. Raw IDs/keys/JSON are never the default UI simply because the backend accepts them.

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

## 9A. CI failure prevention and recurring-pattern promotion

CI is a verification layer, not the first place a worker should discover predictable repository rules.

Every worker MUST proactively avoid known recurring failure patterns before pushing code. A worker may not treat "tests pass" as sufficient if formatter, static-analysis, policy, preview, packaging, migration, runtime-parity, or other required gates are still capable of failing deterministically.

### Mandatory pre-push prevention rules

Before every push that changes executable code, workflows, migrations, tests, generated runtime assets, or release packaging, the worker MUST run or otherwise reproduce the relevant repository checks for the changed area whenever the required execution environment is available.

At minimum:

- **Branch / repository policy:** verify the branch name and task relationship comply with repository policy before the first implementation push. Issue-scoped branches such as `fix/<issue>-...`, `feat/<issue>-...`, or the repository-approved equivalent must be used. Do not weaken policy checks to make an invalid branch pass.
- **Laravel / PHP formatting:** run the same Pint/lint contract used by CI. In particular, avoid recurring failures involving `braces_position`, quote style, import ordering, blank-line rules, PHPDoc formatting, unary/operator spacing, and compact/empty constructor bodies when the repository formatter expands them.
- **Do not guess formatter output:** when Pint reports a style rule, reproduce the exact formatter result locally with `vendor/bin/pint <affected-path>` (or an equivalent isolated copy) and then verify with `vendor/bin/pint --test <affected-path>`. For promoted-property constructors with an empty body, preserve the formatter's exact multiline parameter layout and single-line empty body instead of manually toggling brace placement across pushes.
- **Multiline PHP method signatures:** for multiline methods/functions with a declared return type, follow the repository's Pint-canonical brace placement exactly: the opening `{` belongs on the same line as the closing `): ReturnType {`. Do not apply the ordinary single-line method brace layout to a multiline signature.
- **Backend tests:** run the focused affected tests and the required backend suite when practical. Passing tests does not waive lint or static-analysis requirements.
- **Static analysis / typing:** run the repository's PHP static-analysis/type checks for backend changes. Do not silence a real type defect merely to satisfy the analyzer.
- **PHP syntax / patch integrity:** validate modified PHP files after scripted or generated edits. Never leave literal escape text such as `\n` where an actual newline is required, and do not assume a mechanically generated patch is syntactically valid.
- **Flutter:** for every affected Flutter app, run `flutter analyze` plus the relevant tests. Treat analyzer warnings/errors, stale endpoint assumptions, invalid documentation markup, and nullability/type drift as pre-push defects.
- **APP-PREVIEW:** preview/runtime changes must satisfy branch-scope guards, backend security/contract acceptance, Customer/Driver runtime matrices, and embedded-vs-standalone parity where applicable. A successful visual render alone is not sufficient.
- **UI Visual QA:** changed screens must open in the real runtime, resolve real dynamic data, avoid permanent loading/error states, and remain compatible with the repository's visual evidence selectors/contracts. Do not implement screenshot-only or mock-only behavior to satisfy visual gates.
- **Database migrations:** migrations must be validated against the supported production database family, including forward migration and rollback when rollback is supported. Pay particular attention to MySQL/MariaDB index, foreign-key, and rollback differences.
- **Required CI child jobs:** do not infer readiness from top-level green checks alone. Inspect the required gate's child jobs; one failed child means the PR is still red.
- **Branch drift / exact-head validation:** before final merge, compare against current `main` and ensure required CI belongs to the exact current PR head. If `main` advanced in a way that changes validation relevance, update/revalidate the existing branch.
- **Packaging / release artifacts:** APKs, update bundles, dashboards, and other release artifacts must be built from the exact final validated head. Do not deliver an artifact produced from an older SHA as if it represented the latest code.

### Known recurring FOODEX failure patterns

The following classes have already repeated in repository history and MUST be treated as known traps:

1. repository-policy failure caused by a non-compliant branch name;
2. Laravel Pint failures after otherwise-successful backend tests, especially braces position, single-quote style, import ordering, and constructor/body formatting; repeated manual brace-only fixes are themselves a known failure pattern and must be replaced by running Pint to generate the exact canonical form;
3. PHP static-analysis/type failures after formatter fixes;
4. syntax defects introduced by scripted/mechanical patches;
5. Flutter analyzer failures caused by typing, documentation syntax, or stale assumptions;
6. Flutter tests that encode obsolete endpoint/runtime assumptions;
7. APP-PREVIEW acceptance branch-scope failures;
8. APP-PREVIEW backend security/contract failures;
9. Customer/Driver preview runtime or embedded/standalone parity failures;
10. FOODEX UI Visual QA runtime-evidence failures;
11. MySQL/MariaDB migration or rollback incompatibilities;
12. Required CI appearing mostly green while a nested child job is red;
13. PR branch drift after `main` advances;
14. release/package artifacts being generated from a head other than the final validated SHA.

A worker encountering one of these patterns should first apply the established prevention rule rather than rediscovering the failure through repeated CI pushes.

### Recurring Failure Promotion Rule

This repository follows a **learn-once, prevent-forever** policy.

When a CI, build, test, lint, static-analysis, preview, packaging, migration, runtime, or repository-policy failure is observed repeatedly, the active worker MUST determine whether it represents a reusable repository pattern.

A failure becomes a **Recurring Failure Pattern** when either:

- substantially the same root cause is observed at least twice across repository history, PRs, branches, or workers; or
- one occurrence exposes a deterministic repository constraint that is highly likely to affect future workers and can be prevented safely before push.

When a failure qualifies, the worker owning the current task MUST, on the same existing branch/PR when scope permits:

1. fix the actual current defect without weakening the required gate;
2. document the root cause in this section or the most specific relevant policy section;
3. add a concise prevention rule stating what future workers must do before push;
4. add the exact local/preflight command or validation step when one exists;
5. prefer adding or strengthening an automated preflight/check when the pattern can be caught deterministically and cheaply;
6. avoid encoding one-off business data, IDs, credentials, environment secrets, or brittle task-specific values into the policy;
7. preserve the existing One Task = One Branch = One PR rule while making the policy update.

The purpose of promotion is prevention, not bypass. A recurring failure MUST NOT be "solved" by disabling, skipping, weakening, ignoring, or broadly exempting a required CI gate unless the repository owner explicitly changes that gate's requirement.

### Worker completion check

Before declaring a coding task complete, the worker must ask:

> Did this task reveal a failure mode that another worker is likely to hit again?

If yes, and the rule is not already captured, the worker must promote it under the Recurring Failure Promotion Rule before final completion.


---

## 9B. First-Run Green / Zero-Red worker contract

FOODEX workers must treat remote CI as final verification, not the normal debugging loop.

The canonical operating contract is documented in:

- `docs/worker-rules/FIRST_RUN_GREEN.md`
- `docs/worker-rules/CI_FAILURE_PATTERNS.md`

At task start, workers SHOULD bootstrap repository state with:

```bash
bash ./scripts/worker-start.sh
```

Before the first push of executable changes, every worker MUST run:

```bash
bash ./scripts/worker-preflight.sh --fast
```

Before declaring a PR ready for final merge validation, the worker SHOULD run, whenever the required local toolchain/environment is available:

```bash
bash ./scripts/worker-preflight.sh --full
```

The worker must not push merely to discover deterministic formatter, syntax, analyzer, static-analysis, branch-policy, or focused-test failures that the preflight can reproduce locally.

### Required failure ordering

Workers and CI should detect cheap deterministic failures before expensive validation:

1. branch/repository policy;
2. diff integrity and syntax;
3. formatter/lint;
4. static analysis / Flutter analyzer;
5. focused and subsystem tests;
6. database/service acceptance;
7. Android/iOS/preview compilation;
8. runtime, visual and parity evidence;
9. packaging/release validation;
10. exact-head final required gate.

A heavy build should not consume runner capacity when an earlier deterministic gate for the same affected area is already red.

### Formatter canonical-output rule

Do not guess formatter output.

For PHP/Pint failures, run Pint on the affected file/path to obtain the canonical representation, then verify with `--test`. This specifically includes imports, quote style, PHPDoc, operator spacing, constructor bodies and multiline method signatures.

For a multiline PHP method signature with a declared return type, preserve the Pint-canonical brace position rather than applying the single-line method style by intuition.

### Changed-area validation

Validation scope must come from the actual git diff. Customer-only changes should not trigger Driver work unless shared packages/contracts are affected, and Driver-only changes should not trigger Customer work unless shared dependencies require it.

Shared/auth/tenant/pricing/ledger/migration/networking/preview/release changes are high-risk and may expand validation automatically.

### Recurring Failure Promotion Rule

FOODEX follows a **learn once, prevent forever** rule.

A failure is promotion-worthy when:

- substantially the same root cause has occurred at least twice; or
- one occurrence reveals a deterministic repository constraint that future workers can safely detect before push.

For every promoted failure, the worker must:

1. fix the root cause on the existing task branch/PR;
2. add/update the failure in `docs/worker-rules/CI_FAILURE_PATTERNS.md`;
3. add the cheapest reliable local/preflight detector when practical;
4. add a regression test when it represents application behavior;
5. update this policy or the First-Run Green contract when worker behavior must change;
6. never disable, weaken, skip or broadly exempt a required quality gate merely to remove red status.

Repeated pushes with the same deterministic failure fingerprint are prohibited process behavior. Reproduce locally or obtain new evidence before the next push.

### Flaky-test rule

Never rerun a nondeterministic test until it happens to pass and call that a fix. Identify and remove the unstable dependency (clock, random seed, network, ordering, shared state, external service, etc.) or document a repository-owner-approved quarantine.

### Exact-head evidence

Required checks and deliverable artifacts must belong to the current PR head SHA. A build from an older SHA is not valid evidence for a newer head.

If `main` advances in a way that can affect the task, revalidate the same branch before merge.


### Post-merge main parity rule

A PR must not be considered merge-ready only because its pull-request checks are green.

Workers MUST identify workflows that run on `push` to `main` for the changed area and reproduce their deterministic preconditions before merge. In particular, deployable changes under `backend/`, `apps/customer_app/`, or `apps/driver_app/` must satisfy the same release-version contract that `FOODEX Trial Distribution Bundle` enforces after merge.

If deployable code changed, `VERSION` must be bumped relative to the PR base and Customer/Driver mobile version identities must remain synchronized with `VERSION`. This is enforced locally and in PR CI by `scripts/validate-premerge-release-version.sh`.

A post-merge-only red workflow that could have been predicted on the PR is a prevention failure and must be promoted into pre-merge validation.


### Release identity synchronization rule

Release/version work is an atomic identity update, not a sequence of independent edits.

When a release branch or task changes `VERSION`, the worker MUST synchronize every repository-owned release identity in the same coherent change before pushing. At minimum, the worker must validate:

- root `VERSION`;
- Customer `pubspec.yaml` version/build identity;
- Driver `pubspec.yaml` version/build identity;
- Customer visible/runtime `_appVersion` identity;
- Driver visible/runtime `_appVersion` identity;
- release notes title/identity;
- CHANGELOG release entry;
- any diagnostics/version-policy identity explicitly covered by release tooling.

A partial version bump is a known FOODEX failure pattern. Do not push a release branch with only `VERSION`/pubspec bumped while runtime/UI identities still point to the previous release.

For release-related changes, run `bash scripts/release-readiness.sh` before push in addition to the normal worker preflight.

Release validation scripts must emit the name of the failed invariant whenever practical; silent `test`/exit failures materially slow diagnosis and should be replaced with actionable errors when touched.


### Main-push release-intent rule

Normal feature/bug merges to `main` do **not** imply a release publication and do not require an immediate `VERSION` bump.

Release-only workflows such as `FOODEX Trial Distribution Bundle` must distinguish ordinary deployable-code merges from explicit release intent. Explicit release intent exists when:

- `VERSION` changes relative to the previous authoritative base; or
- the repository owner/manual workflow dispatch explicitly requests distribution.

A workflow must not fail `main` merely because `backend/**`, `apps/customer_app/**`, or `apps/driver_app/**` changed while `VERSION` remained unchanged. In that case, release/distribution work must be skipped cleanly and reported as "no release intent".

When release intent exists, the full atomic release identity contract still applies: root VERSION, Customer/Driver mobile identities, runtime/UI identities, release notes, changelog, registry and immutable publication guards must remain synchronized.

Any main-only red caused by a release workflow misclassifying a normal feature merge as a release is a CI-policy defect and must be corrected in the workflow trigger/gating logic rather than forcing unrelated feature work to publish a new version.

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
- a new worker should be able to resume without asking the user to reconstruct history;
- after any connection interruption or uncertain GitHub mutation result, re-fetch the live Issue/branch/PR/head before retrying the mutation;
- branch creation, PR creation, workflow rerun and completion mutations must be idempotent: discover/reuse existing state before creating or rerunning anything.

If a worker/session hangs, another worker should take over the same task using the same branch/PR.

If the repository owner explicitly identifies a particular previous chat/worker as interrupted and sends `HANDOFF` / `AUTO-HANDOFF`, that is an **immediate lease transfer for that identified task**. Do not wait for the passive 30-minute stale timeout. The replacement worker must inspect GitHub first, reuse the existing Issue/branch/PR/head, and the previous worker must stop if it later returns.

For a broad umbrella command such as `FOOD #<umbrella> AUTO-HANDOFF` where several lanes may exist, use the explicit-owner Mission takeover rule in section 20: red is immediate, running CI is preserved, otherwise 10 minutes without real execution evidence is enough to supersede an older chat lease.

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
4. never steal a genuinely executing `worker:active` lease merely because multiple workers exist; for an explicit owner AUTO-HANDOFF mission, "genuinely executing" is determined by current-head CI or execution evidence within the last 10 minutes, not by ordinary comments or labels.

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

The owner may also use the bare active-project commands:

- `حرك مشروع FOOD`
- `اشتغل على مشروع FOOD`
- `FOOD MISSION`

These commands must resolve the current open `[MISSION][ACTIVE]` FOOD umbrella from live GitHub state and execute it using Section 20. The user does not need to know or repeat the umbrella number.


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
6. never collide with a genuinely executing `ACTIVE_PEER`; current-head CI/queued work remains owned, while red/CI_FIX or merge-conflicted state is immediate takeover-eligible;
7. under an explicit repository-owner `FOOD #<umbrella> AUTO-HANDOFF` command, a lane with **no running current-head CI and no commit/head movement or machine HEARTBEAT for 10 minutes** is takeover-eligible on the same Issue/branch/PR even though the passive Watchdog's silent timeout is 30 minutes;
8. never duplicate a branch/PR just to increase parallelism.

Parallelism is encouraged only across file/scope-disjoint lanes.

If multiple worker chats receive the same umbrella command, each worker must re-run preflight immediately before claiming work and skip lanes that gained a fresh claim. This makes repeated identical commands self-distribute across available lanes instead of duplicating implementation.

After posting a claim, re-fetch the Issue's latest comments/PR state before the first code edit. If another valid claim or newer execution checkpoint won the race, release the lane and select another.

### Explicit owner Mission pulse

When a live worker receives `FOOD #<umbrella> AUTO-HANDOFF` directly from the repository owner, treat it as an instruction to make forward progress now, not merely to report queue status.

For each incomplete lane:

- red CI/check/status or merge conflict => take over immediately;
- current-head CI queued/running => do not collide; classify `WAITING_CI` and inspect another independent lane;
- no current-head CI and execution evidence newer than 10 minutes => respect the active peer;
- no current-head CI and no commit/head movement or machine HEARTBEAT for 10 minutes => take over the same Issue/branch/PR immediately;
- coordinator comments, labels, review chatter and "please continue" messages do not reset the 10-minute clock.

This 10-minute explicit-owner rule is intentionally shorter than the passive 30-minute Watchdog timeout.

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


---

## 21. FOOD Assistant V1 isolated feature-train policy

The authoritative Assistant V1 plan is `docs/architecture/FOODEX_ASSISTANT_V1_CHARTER.md`.

Assistant V1 is a deliberately isolated development track.

### Mandatory classifications

Use these title prefixes:

- `[PLATFORM-BUG]` for normal FOODEX defects;
- `[HOTFIX]` for production-critical FOODEX defects;
- `[AI-V1]` for Assistant V1 implementation;
- `[AI-BUG]` for Assistant-specific defects;
- `[AI-GOV]` for Assistant governance/coordination.

Assistant child Issues/PRs must state `Production release blocker: NO` unless the repository owner explicitly reclassifies the exact Issue.

### Assistant integration target

Assistant child implementation branches target the dedicated integration branch:

`feat/assistant-v1-integration`

They do not target `main`.

The integration branch is a feature-train target and is not itself an atomic implementation Issue/worker lease.

Only the explicit final Assistant integration Issue may open the integration branch toward `main`.

### Platform release precedence

Platform bugs, production hotfixes and normal releases always proceed independently from `main`.

If platform work and Assistant work need the same file, the platform work wins on `main`. Release it first when required. Assistant work then absorbs latest `main` and owns its adaptation/conflict.

Never delay a platform release because:

- an Assistant child Issue is unfinished;
- Assistant CI is red;
- the Assistant integration branch is behind `main`;
- an Assistant worker/chat is interrupted.

### Release identity fence

Assistant child work must not modify `VERSION`, Customer/Driver release identity, normal release artifacts/distribution or production activation unless the exact Issue is the final Assistant integration/release task.

### Assistant mission autonomy

`FOOD #<assistant-umbrella> AUTO-HANDOFF` means drain the complete Assistant umbrella continuously under Section 20.

Repository-local blockers are not reasons to ask the owner for help. Workers must fix CI/tests/lint/conflicts/drift on the existing Issue/branch/PR and continue.

Assistant mission workers stay inside the Assistant umbrella. They do not consume unrelated platform-bug work merely because it is available.

A broken chat/session never owns authoritative state; GitHub state must be sufficient for immediate same-branch takeover.

### Shared-file fence

When an unrelated platform Issue has active ownership of a shared file, the Assistant lane must continue all disjoint work it can perform, then absorb the platform change after merge. It must not block or compete with the platform worker for that file.

### AI bugs vs platform bugs

A defect that reproduces with the Assistant disabled is a platform bug and is fixed on the platform track.

A defect confined to Assistant behavior is an AI bug and stays on the Assistant track.

If Assistant usage reveals an underlying platform defect, fix the platform defect on `main` first, then adapt the Assistant integration branch.



---

## 22. Release identity registry and immutable version policy

`main` is the only authoritative source of FOODEX release identity.

The machine-readable registry is:

`docs/release/RELEASE_REGISTRY.json`

Mandatory rules:

- every promoted FOODEX version must be merged to `main` before it is treated as released;
- every promoted version must append exactly one immutable entry to the release registry;
- `VERSION`, Customer/Driver package identity, release notes, Dashboard update manifest, package checksum and registry current version must agree;
- a version that already exists in the `main` registry is permanently consumed and must never be regenerated for later fixes;
- post-release fixes require the next semantic version (for example, fixes after published 1.0.40 must ship as 1.0.41);
- published registry history is append-only: old entries may not be edited, reordered or removed;
- a release branch may generate a Dashboard update only when its target `VERSION` is not already registered on `main`;
- `release/*` branches are temporary delivery branches, not long-lived release records; after their PR is merged to `main`, safe branch GC should delete them when they are not protected, do not have an open PR, are not referenced by open Issue worker state, and their completion can be verified safely;
- release history must be recovered from `main`, tags/release metadata and `docs/release/RELEASE_REGISTRY.json`, never by keeping merged `release/*` branches indefinitely;
- exception: `release/221-generated-trial-bundle` is a repository-managed generated distribution branch maintained by the trial-distribution workflow and must be preserved by branch GC; it is not authoritative release identity and must never replace `main`, tags/releases or the release registry as the source of release truth;
- generated release artifacts are not authoritative merely because a workflow artifact or branch exists; promotion is complete only after the release metadata/artifacts are merged and registered on `main`;
- workers must never tell the owner to deploy a package whose version is already registered for a different release payload.

Normal feature/bug PRs may merge without immediately bumping `VERSION`; however, the next distribution/release task must allocate a new version and register it before any new package is promoted.

This policy is enforced by repository CI and must not be bypassed to publish a release.


---

## 23. JOURNEY mission keyword — Customer + Driver

The single-word repository-owner command:

```text
JOURNEY
```

is a persistent coordinated Mission / Drain command for both:

- **#675** — Customer Journey V2, new-only Customer App end-to-end journey;
- **#686** — Driver Journey V2, assignment-to-proof-of-delivery end-to-end journey.

When `JOURNEY` is received in any chat/session, the worker must:

1. reconstruct both umbrellas and all current child lanes from GitHub state;
2. apply Section 20 Mission/Drain rules without asking the owner to repeat context;
3. select the highest-priority safe non-conflicting lane across both umbrellas;
4. reuse existing Issue/branch/PR state where present;
5. respect fresh peer ownership and current-head running CI;
6. immediately take over red/stalled/handoff-ready work on the same branch/PR according to policy;
7. continue draining after the first child completes;
8. stop only when **both #675 and #686 are COMPLETE**, or every remaining incomplete lane is genuinely HUMAN-GATED.

### NEW-only invariant

For both journeys:

- production runtime must converge on one **new** Customer journey and one **new** Driver journey;
- old runtime code may be read temporarily as behavioral reference only;
- workers must not repair, expand, or retain a legacy screen/flow as the final production solution;
- after parity, obsolete routes/screens/widgets/tests/assets must be deleted, not merely hidden;
- no silent fallback may return users to legacy Customer or Driver flows;
- #683 owns final Customer shared wiring and legacy purge;
- #694 owns final Driver shared wiring and legacy purge.

### Customer lanes (#675)

- #676 Context/Auth/guest-session kernel;
- #677 NEW Retail catalog;
- #678 NEW Retail commerce/checkout;
- #679 NEW Customer account surfaces;
- #680 NEW Orders/tracking;
- #681 Dashboard registration + order-created operational notifications;
- #682 Guest full-journey E2E acceptance;
- #683 final Customer integration + legacy purge.

Lanes #676-#681 are designed to run in parallel. #682 may scaffold concurrently but closes only against integrated behavior. #683 is the Customer convergence lane.

### Driver lanes (#686)

- #687 authoritative Driver state machine + proof contract + idempotency;
- #688 NEW assignment/accept/pickup/start-delivery UX;
- #689 delivered/failed decision + mandatory proof-photo UX/upload;
- #690 navigation + active location lifecycle;
- #691 Driver/Customer/Dashboard lifecycle notifications;
- #692 Dashboard delivery timeline + proof evidence;
- #693 full Driver assignment-to-proof E2E acceptance;
- #694 final Driver integration + legacy purge.

Lanes #687, #688, #689, #690 and #692 are designed to run immediately in parallel. #691 may progress on disjoint notification-service work, but it must not edit `DashboardOperationalNotifier.php` until Customer lane #681 is merged. #693 may scaffold concurrently but closes only against integrated behavior. #694 is the Driver convergence lane.

### Mandatory Customer Guest acceptance

#675 is not complete unless automated acceptance covers a real guest journey from Marketplace through Retail browse/product/cart, Login-or-Register handoff, same-store guest-cart merge, saved address, backend-supported payment, checkout, created order and order details/tracking, with exact store context preserved at every boundary and cross-store isolation verified.

### Mandatory Driver acceptance

#686 is not complete unless automated acceptance covers Dashboard assignment → Driver push → authoritative assignment open → Accept → Pick up/Receive → Start Delivery decision sheet → Out for Delivery → Delivered decision sheet → required proof photo → final Delivered state, plus failed-delivery, reassignment and cancellation paths.

Successful delivery must not become terminal without a valid proof-of-delivery image accepted by the backend. Retry/idempotency must prevent duplicate order transitions, delivery proofs, audit entries and user-visible notifications.

### Notification acceptance across both journeys

The combined mission is not complete unless automated tests prove:

- successful new Platform Customer registration/subscription creates one deduplicated Dashboard operational notification;
- ordinary login does not create a false registration notification;
- successful checkout/order creation creates one deduplicated Dashboard order-created notification;
- Driver assignment/reassignment/unassignment/cancellation reaches the correct Driver and Dashboard audiences;
- Driver accepted, picked-up, out-for-delivery, failed and delivered lifecycle events generate the required Dashboard and Customer notifications from the authoritative backend transition path;
- stale/revoked Driver push payloads cannot grant assignment/order access;
- notification audience, store/channel scope, deep links and deduplication follow existing authorization conventions;
- Customer notifications never expose internal proof storage paths or driver-only sensitive notes.


---

## 24. C13 mission keyword — complete 13-screen Customer Journey

The single repository-owner command:

```text
C13
```

is a persistent Umbrella Mission / Drain command for:

- **#858** — Complete 13-screen Customer Journey;
- shared foundation **#859**;
- Customer Screens **#860-#872**;
- final integrated gate **#873**.

The authoritative plan is:

`docs/execution/C13_CUSTOMER_13_SCREEN_JOURNEY_PLAN.md`

When `C13` is received in any chat/session, the worker must:

1. reconstruct #858 and #859-#873 from live GitHub state;
2. apply Section 20 Mission/Drain semantics;
3. reuse every existing Issue/branch/PR and never create a replacement branch merely because another worker stopped;
4. start/take over only dependency-unblocked, non-conflicting lanes;
5. preserve the existing Customer visual language and routes; do not create a new canonical Customer screen or redesign to avoid finishing an existing screen;
6. require every promised function to be visibly reachable through normal navigation or an obvious action — API-only, hidden, orphaned or undocumented-deep-link functionality does not count;
7. require authoritative live data, explicit loading/empty/error/stale states and no fake-live fallback;
8. preserve the shared financial semantics from #859: **customer owes company / company owes customer**, credit limit and available credit are distinct;
9. continue draining after each merge;
10. declare C13 COMPLETE only after #873 passes on integrated `main` and #858's own completion rule is satisfied.

### C13 canonical screens

1. Entry / Login
2. Customer Dashboard
3. Purchases Report
4. Top Purchased Products
5. Invoices List
6. Account Statement
7. My Orders
8. Order Details & Tracking
9. Invoice Details
10. Products Browse
11. Product Details
12. Cart & Checkout
13. My Account / Profile

Existing address/settings/notifications/checkout subroutes remain owned sub-surfaces and do not become additional canonical screens.

### C13 completion invariant

A worker must never report **"C13 finished"** while:
- any of #859-#873 is incomplete;
- any required screen/action is hidden, orphaned or reachable only by undocumented deep link;
- any Customer financial screen disagrees with the authoritative ledger;
- any required screen still presents mock/stale data as live;
- any required AR/EN, RTL/LTR, authorization, isolation or integrated E2E gate is red.

- **Multiline PHP method signatures:** do not infer brace/operator style from older files. The current CI-resolved Pint contract is authoritative: for multiline methods/functions with a declared return type, Pint places the opening `{` on the same line as `): ReturnType {`; for negated `isset` guards, preserve Pint's exact spacing (`! isset(...)`). If dependency drift changes formatter output again, run Pint on the affected file and promote the generated diff here before repeated pushes.

---

## 25. Active FOOD Mission resolution — UI/UX v4.2

The current active mission is registered by live GitHub Issue state and, on the mission integration branch, by:

`docs/execution/ACTIVE_FOOD_MISSION.json`

For the current mission:

- umbrella: **#1001**;
- mission ID: `UIUX-V42`;
- execution plan: `docs/execution/UIUX_V42_AUTONOMOUS_MISSION_PLAN.md`;
- integration target: `release/1.0.58-van-complete`;
- final convergence gate: **#1012**;
- maximum implementation parallelism: **6 active lanes**.

When the repository owner says `حرك مشروع FOOD`, `اشتغل على مشروع FOOD`, or `FOOD MISSION`:

1. search live GitHub for the open `[MISSION][ACTIVE]` umbrella;
2. treat the result as `FOOD #<umbrella> AUTO-HANDOFF`;
3. reconstruct every required child from the umbrella and live GitHub state;
4. prioritize repository-local red CI/conflicts, then merge-ready/takeover lanes, then dependency-critical ready lanes;
5. reuse the exact canonical branch recorded in the child Issue;
6. never create a duplicate branch or PR to recover from a failed/disconnected chat;
7. preserve latest-head running CI and use another safe lane rather than duplicating the run;
8. after each child completes, return to the umbrella and continue draining;
9. stop only at COMPLETE or a genuine all-remaining-lanes HUMAN_GATE state.

For #1001, only #1012 may declare the plan fully converged and close the umbrella.

If the machine-readable registry ever disagrees with live Issue/branch/PR state, **live GitHub state wins**. The registry locates the mission; it is not a cached status database.

