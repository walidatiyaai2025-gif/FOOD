# V2 #2696 — Accounts Receivable Officer authorization (T122R16)

**Integration boundary:** `Foodex_V2` only. Parent #2503. No main, production, release, tag or live finance migration.

## Existing authoritative implementation

The built-in `AR_OFFICER` role is defined in `backend/config/job_roles.php`, seeded by `CoreReferenceSeeder` using `BuiltInJobRoleCatalog`, and localized in `backend/lang/{ar,en}/job_roles.php`. The current role is global with tenant business scope, requires the `finance` module, and grants `finance.view`, `finance.manage` and `reports.view`. It has **no** `finance.post`, `finance.approve`, `reports.export`, treasury/security, order dispatch or inventory grants. Never infer posting or approvals from a broad manage permission.

Placement is **Reuse**, not a new Sidebar route: the existing Finance → ERP Finance workbench (`admin.erp.finance.index`). Server authority is resolved by real role permission, Finance module lifecycle and legal-entity capability entitlement. Its receivables view is the existing `receivables` tab. The access matrix currently maps `finance.ar.read` to `finance.view` plus entitlement. No separate `AR_OFFICER` application screen is justified.

## Regression evidence added

`backend/tests/Feature/V2ArOfficerAuthorizationContractTest.php` verifies:
- Seeded immutable bilingual system role, tenant/global boundary and negative permission bundle.
- Disabled Finance module denies direct workbench access and effective Finance grants; temporary enablement restores grants but not post/approve/export; disabling retains auditable role assignments.
- A scoped per-user **deny** of `finance.manage` wins over the role while retaining read-only permission.
- A global AR Officer with an unrelated Retail Operations assignment cannot receive Finance authority at that Retail store or access its workbench.
- Deactivating the role revokes effective grants without deleting the user-role audit pivot.

**Important coverage boundary:** these focused tests do not certify the entire Accounts Receivable collection/settlement workflow, real ledger reconciliation, approvals, cross-legal-entity data reads, or all Enterprise Finance entitlements. `finance.manage` is an existing broad permission shared by multiple finance actions, so its business-specific mutation scope needs independent reconciliation with Finance/API owners before certifying full T122R16 acceptance. Do not claim that a green role test alone completes the source requirement.

## Concurrent-work exclusion

During preflight PR #3218 was OPEN on `Foodex_V2` and changed `backend/app/Enterprise/Finance/AccountsReceivableService.php` and `backend/app/Http/Controllers/Admin/FinanceWorkbenchController.php`. These shared Finance controller/service files are read-only for #2696. The **initial** PR added only issue-scoped tests and this source record. The latest source-hardening commit also changes the non-overlapping `FinanceWorkbenchAccess.php` guard; see the current checkpoint below. Related #2695 Accountant and #2697 AP Officer have separate issue ownership.

## Cross-app impact

- **Customer:** no customer app, API, identity, B2B credit, price or order contract changes in this test/document delta; no Flutter work required for this delta.
- **Driver:** no Driver auth, B2C dispatch, wallet or settlements contract changes; B2C Driver-only dispatch invariant unchanged.
- **Van:** no Van auth, B2B visits, consignment, collections or routes change; B2B Van-only dispatch invariant unchanged.

If subsequently implementing AR mutations/API contracts, separately re-evaluate all three applications and link affected V2 mobile issues.

## Verification and completion policy

Run focused Laravel tests, syntax and Pint/PHPStan, AR/EN localization, V2 isolation and repository policy, plus the exact-head Required CI and MySQL/Redis fresh install, upgrade and recovery checks. Merge **only** on green exact head into `Foodex_V2`. Keep #2696 **OPEN** unless all T122R16 source/financial/approval, role scope and cross-app acceptance criteria have actually been completed; manual device/browser UAT is deferred under #2503.


## 2026-11-16 owner-requested source completion checkpoint

The original issue-scoped branch was restored from the preserved historical HEAD,
merged with the latest isolated Foodex_V2 commit (without rewriting its two original
files), and original draft PR #3221 was reopened. Its historical green CI did **not**
represent acceptance of the current isolated head.

**Least-privilege acceptance decision pending source integration:** the merged #3872
negative-channel contract intentionally does not admit AR_OFFICER to the broad B2B
\`global_roles\` or any Retail store role. Existing \`FinanceWorkbenchController::contextOptions\`
requires \`AdminNavigation::canAccess(actor, 'b2b')\` before enumerating wholesale
Finance contexts. Consequently, an otherwise valid AR-only user cannot follow a
positive Finance → Receivables journey today; merely adding \`finance.view\` or
Finance legal-entity entitlements does not fix the admission path. An AR-only
workflow must respect explicit module readiness, legal-entity + store-scoped
AR entitlements, no AP/GL/Treasury access, and deny Retail or foreign company scope.
Global B2B channel admission is **not** a safe substitute.

An additional bilingual real-HTTP regression now exercises direct GL create,
approval-request, posting, opening-balance approval/post attempts and ensures
these are denied to the standalone AR_OFFICER without changing journal/ledger rows.
This covers a negative SoD invariant, **not** the outstanding positive workflow.

**Owned-file coordination:** issue #2633 original PR #3309 is actively editing
\`FinanceWorkbenchController.php\`, \`AccountsReceivableService.php\`,
Finance routes, view and locale files. #2696 has notified its owner and must wait
for integration or explicit file-fence handoff before altering those shared paths.
In parallel, #2696 may edit only its own regression file and this trace.

**Still required before merging/closing:** a real authorized B2B AR-only positive
HTTP and Sidebar journey; per-action draft/manage versus post/approve; legal-entity,
cross-company, store and channel negative evidence; actual maker/checker and
audited collection/recovery boundaries; any required API/app impact trace; reviewed
HIGH-risk changed-file audit; exact-final-head CI. No source-scoped acceptance
is waived by this test increment. Customer/Driver/Van runtime/API remain unchanged
by the earlier test/document-only patch. The subsequent Workbench role hardening does not change mobile runtime, Finance endpoints or dispatch.


## 2026-11-16 — Current non-overlapping Workbench source-hardening increment

**Current original PR #3221:** OPEN/DRAFT, base `Foodex_V2` only. Current
source-checkpoint HEAD `fa206eba0e354c28084443832af27c1d638db70b`
(the actual head and exact-head CI must always be rechecked before merging).

**Changed code and tests:**
- `backend/app/Enterprise/Finance/FinanceWorkbenchAccess.php`: require the
  `finance.ar.read` store/legal-entity entitlement for Workbench eligibility
  whenever an actor holds `AR_OFFICER`; `canPerform` now fails closed for all
  non-AR Finance capabilities for that role, even if a second B2B `FINANCE`
  assignment contributes broad `finance.manage`. Normal actors without
  `AR_OFFICER` retain their prior Finance access matrix.
- `backend/tests/Feature/V2ArOfficerAuthorizationContractTest.php`: a
  deterministic active wholesale store and enterprise hierarchy, real legal
  entity AR+GL+AP entitlements, mixed AR/Finance role checks, authorized AR
  Workbench GET in Arabic/English, direct GL journal-create 403 and unchanged
  journals, denied GL/AP/Foundation/approval capability checks, revocation of
  AR entitlement while GL remains and original role/Store isolation tests.
- Latest CI must be checked on this exact head. Earlier terminal-success
  source-fixture head `98ec8fd` does **not** prove these new runtime changes.
  The new HEAD also corrects the exact Pint ordered-import/method-spacing
  failure from predecessor `6734812` (Required run #43868915795).

**High-risk residual acceptance blockers (do not waive):**
1. Standalone `AR_OFFICER` cannot enumerate or resolve a Wholesale context:
   `User::hasInheritedPermission`, `AdminNavigation::canAccess`,
   `TenantContextResolver::wholesale` and
   `FinanceWorkbenchController::contextOptions` apply the intentional B2B
   global-role boundary. The mixed `AR_OFFICER`+`FINANCE` positive journey
   is test evidence for limited Workbench use only, not the required standalone
   AR-only workflow.
2. `FinanceWorkbenchController::accessMatrix` and the Finance/Payables
   domain services remain separately authoritative; the new
   `FinanceWorkbenchAccess` guards do **not** prove all unrelated GL/AP tabs,
   direct domain-service use or Treasury access denied. Generic
   `finance.manage` and an unapproved incompatible-role matrix under #2851
   prevent a full cross-domain SoD assertion.
3. #2633 / original PR #3309 owns shared Finance controller, receivables
   service, Web routes, Dashboard view and AR/EN locale files. They were not
   modified or silently taken over in this issue.
4. Before #2696 merge/closure: implement the positive *standalone*
   B2B AR workflow with trusted scope and navigable entry; enforce exact
   action-level RBAC/SoD/maker-checker, cross-entity/store/company/channel
   authorization, real-row GL/AP/Treasury denial, data preservation, retries,
   audit/recovery and browser AR/EN; re-evaluate Customer/Driver/Van impact
   after any API change; complete a HIGH-risk final-head code review and get
   every required CI check terminal SUCCESS.

**Current status:** SOURCE_INCOMPLETE; **DO NOT MERGE / DO NOT CLOSE**;
no main, version promotion, ZIP release or production deployment.

## 2026-11-18 — Original-branch owner-directed source continuation (NOT final acceptance)

- Safely reconciled original #3221 to Foodex_V2@1eacd4a925802e22abd36a67d4d8712b60496449 by a **non-force two-parent commit** ef64886; original PR and all three original issue-owned paths preserved; no #2633 shared file overwritten. At that preflight PR diff contained only three original files and was ahead 21/behind 0 after edits.
- Added `FinanceWorkbenchAccess::canReadCapability` for **exact** Finance read capability enforcement. For actors holding AR_OFFICER, every non-`finance.ar.read` Finance read is denied even when an active FINANCE role and broad GL/AP/Foundation entitlements coexist. For other actors the full manifest-backed Finance *.read contract remains intact.
- Added an independently scoped **standalone AR_OFFICER legal B2B permission fallback** in `FinanceWorkbenchAccess`: the operator need not be added to the broad B2B global-role list; requires actual active Wholesale store, existing effective global Finance permission, absence of global/store user override DENY, Finance lifecycle, active legal-entity and store entitlement. AR mutation is confined to `finance.ar.manage`; no GL/AP/Foundation/Treasury, Retail or generic Wholesale role grant is created. This is a backend-domain authority seam, **not yet a working HTTP entry**.
- Added real B2B store/legal-entity positive service tests and store-specific DENY revocation plus mixed-role read/no-escalation tests in original `V2ArOfficerAuthorizationContractTest`. No new route, Sidebar, role grant, policy shortcut, database migration, customer/API/mobile source, or Production certification.
- Latest source commit before this documentation: `956436ff8bcfdf71c3fbae8f9d510d63dc08e3b7`. Required CI, Repository Policy and V2 Isolation for that source were IN_PROGRESS when inspected; after this document change they are **superseded**, and only the new final HEAD's terminal workflow checks may be used for integration.
- The shared `FinanceWorkbenchController`, `AdminNavigation`, `TenantContextResolver`, routes/views and `AccountsReceivableService` remain outside this issue's exclusive file fence because #2633 original PR #3309 independently edits the Finance controller/nav/routes and carries its own authorization/settlement risk. Coordination: #2633 comment #6974002112. Need an explicit source/hunk handoff or accepted upstream integration to wire AR-only B2B selection, Finance HTTP `accessMatrix` through the same capability authority and authorized localized navigation **without** broad B2B admission. Then test real AR positive HTTP, denied GL/AP tab GETs for mixed roles, same-store/foreign-store/retail/explicit-deny, maker/checker, idempotent audited AR actions and positive/negative accessibility.
- **Source complete: NO. Technical review: HIGH risk / pending full PASS. Merge: BLOCKED** until above. This checkpoint is not a waiver for red/missing CI or production/human UAT. Keep #2696 and master #2503 OPEN; no main/releases/deployment.

## 2026-11-18 — Capability-pair hardening and shared route/nav handoff (NOT final acceptance)

- `FinanceWorkbenchAccess::canReadCapability` now accepts only the manifest's
  six known read capabilities. Unknown `finance.*.read` strings fail closed,
  even if a bad entitlement row exists. The shared read-capability set includes
  opening balances and credit terms as well as Foundation, GL, AR and AP.
- `FinanceWorkbenchAccess::canPerform` now validates each capability against
  its canonical permission (`finance.view`, `finance.manage`, `finance.post` or
  `finance.approve`) before checking actor, store, channel and entitlement.
  For example, `finance.view` cannot authorize `finance.ar.manage`, and an
  unknown capability is denied. AR_OFFICER remains limited to the existing
  `finance.ar.read/manage` duty boundary; this adds no post, approval, GL/AP,
  Retail, B2C or generic B2B authority.
- The dedicated feature test now covers known opening-balance/credit-term reads,
  denial of an unknown read capability under the Finance manifest, a valid
  manage pair, unknown mutation denial and mismatched read/manage permission
  pairs. The entitlement manager itself rejects capabilities absent from the
  manifest, so the test fixture grants only declared Finance capabilities.
- **Exact integration dependency remains BLOCKED on #2633 / PR #3309:** the
  standalone AR entry path needs coordinated changes to
  `backend/app/Http/Controllers/Admin/FinanceWorkbenchController.php`
  (`contextOptions()` and `accessMatrix()`),
  `backend/app/Support/AdminNavigation.php` (B2B context admission and Finance
  item projection), and the shared Finance routes/views/AR service. These are
  outside #2696's three-file fence and are owned by the original #2633 PR.
  The #2633 issue is OPEN with `worker:active`; PR #3309 is OPEN/DRAFT at
  `fca26d17c5a4fce6c3f73c9b04f0061062874304` and its current diff owns these
  shared paths. No explicit file/hunk handoff has been granted to #2696. Do not
  widen B2B global roles to bridge this gap. The in-fence service policy is not
  a positive standalone HTTP or navigation journey.
- **Confirmed read-surface gap from the HIGH-risk review:**
  `FinanceWorkbenchController::accessMatrix()` still builds Foundation, GL,
  AP and related read flags from broad `finance.view` plus entitlements; it
  does not call `FinanceWorkbenchAccess::canReadCapability()`. A mixed
  `AR_OFFICER` + `FINANCE` actor with those entitlements can therefore still
  receive non-AR tab/read access through the shared Workbench. The existing
  regression proves a direct GL POST is denied, but does not prove GL/AP/
  Foundation GET denial. This defect is in the #2633-owned controller path,
  remains blocked by that file fence, and keeps #2696 SOURCE_INCOMPLETE.
- Exact pre-edit head `f4c754aa555e5d9323acf6f60cfe17a114080645` Required CI
  #44274079743 was terminal FAILURE: backend tests and MySQL/Redis succeeded;
  the C13 login evidence gate and Operational #734 transition contract failed
  in other owners' files. #2706 actively owns the Operations isolation test;
  neither failing test is in the #2696 fence. This patch does not claim a CI
  result for its new head until the exact-head run is terminal.
- **Source complete: NO. Merge: BLOCKED.** Keep #2696 OPEN and PR #3221
  DRAFT until the shared route/nav handoff, standalone AR positive HTTP path,
  tab-level deny coverage, cross-company/store/channel checks, SoD and audited
  AR action evidence are complete, and the final HIGH-risk review and required
  exact-head checks pass.

## 2026-11-20 — P2696 explicit-deny + read-before-mutation hardening (still blocked)

- Original #2696 / PR #3221 / branch `test/2696-v2-ar-officer-authorization-contract`; base `Foodex_V2` only.
- Source [c3ab055](https://github.com/walidatiyaai2025-gif/FOOD/commit/c3ab055412829340c1ae38a4ea1c5e4709c837e0): before any SUPER_ADMIN-influenced `User::hasPermission` success, an actor holding AR_OFFICER must honor an explicit global or selected-store `finance.view/manage` DENY at the `FinanceWorkbenchAccess` service boundary. An AR draft also requires an independently authorized AR read context and `finance.ar.read` entitlement; a rejected read must not leave a write-only manage path.
- Regression [0155c8b](https://github.com/walidatiyaai2025-gif/FOOD/commit/0155c8bf60f4f3d3514fae6f7c0f7d90fb43e78d) exercises a disposable real B2B store, legal-entity AR read/manage entitlements, mixed AR_OFFICER+SUPER_ADMIN account, baseline allow, selected STORE read DENY, GLOBAL manage DENY, GLOBAL read DENY, Arabic/English, and unchanged Finance journal rows. Privileged `User::hasPermission` returns TRUE in the denial fixture, so the test prevents future bypass by accidentally removing the service-level veto. No mobile/Flutter, shared Finance controller/navigation/routes, CI workflow or production change.
- Risk: **HIGH** (RBAC/Finance). Scope: only the original `FinanceWorkbenchAccess.php`, issue-owned `V2ArOfficerAuthorizationContractTest.php`, and this trace. Business action HTTP/maker-checker/independent direct-route authority remain dependent on original #2633/PR #3309 and unresolved #2851 SoD. No claim of code completion or ready-for-review.
- Previous exact-head Required [#44318186709](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44318186709) failed only outside this ownership fence: C13 Customer app navigation evidence and Operational #734 OrderOperationsIsolationTest method. Backend tests 4,341 PASS; MySQL/Redis PASS. These are neither waivable nor corrected by this service patch. New exact-head CI MUST be checked on the latest commit, not inherited from that run.
- **SOURCE_COMPLETE: NO; TECHNICAL_REVIEW: NOT_PASS_FOR_FULL_ISSUE; MERGE/CLOSE: BLOCKED; MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL.** No main, release, live DB, tag or production action.
