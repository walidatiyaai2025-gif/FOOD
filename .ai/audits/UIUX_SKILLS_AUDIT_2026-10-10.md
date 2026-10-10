# FOODEX UI/UX Skills Audit — 2026-10-10

Scope: repository-native AI UI/UX skill system on Issue #1179 / PR #1180.

This audit compares the current skills against authoritative FOODEX source, route authority, product contracts, regression tests, visual-evidence workflows and production interaction patterns. It audits the **skill system**, not a single page.

## Executive result

The skill layer is already strong on visual identity, component reuse, localization, responsive density, stale/offline truthfulness and screenshot evidence. The remaining risk is not mainly "bad colors"; it is an agent producing a screen that looks FOODEX-compatible while violating canonical routing, authorization boundaries, mutation safety, scalability or accessibility.

The audit therefore strengthens the skills around five non-visual engineering dimensions:
- route/journey authority;
- mutation and retry safety;
- accessibility/semantics;
- pagination/performance/lifecycle;
- durable skill maintenance.

## Evidence inspected

Authoritative sources reviewed include:
- `docs/execution/UI_ROUTE_AUTHORITY.json`;
- `docs/design-reference/SCREEN_MANIFEST.json`;
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`;
- `docs/architecture/FOODEX_BRAND_SYSTEM.md`;
- `docs/quality/LOCALIZATION_CONTRACT.md`;
- current Dashboard brand/component source;
- Customer UI V3 tokens/components;
- Driver active-journey/navigation source;
- Van foundation/inventory/actions;
- `scripts/uiux-v42-recovery-audit.py`;
- `scripts/foodex-uiux-audit.py`;
- Dashboard navigation/pagination/compliance tests;
- Customer route/navigation/commerce tests;
- Driver navigation/active-journey/wallet tests;
- Van application/wallet/screenshot tests;
- Web and mobile visual-evidence workflows.

## Findings

### F01 — CRITICAL — Canonical route authority was under-specified

Evidence:
- FOODEX already owns `docs/execution/UI_ROUTE_AUTHORITY.json`.
- Customer has an explicit authority source/router and `customer_route_authority_test.dart`.
- Driver has channel-partition navigation tests.
- Van has a frozen production screen inventory.
- Dashboard has a visible-navigation authorization audit.

Risk:
An agent can create a visually correct second route/screen/renderer for an existing function, fragmenting behavior and evidence.

Remediation:
- Added `ui-route-authority.md`.
- Route Authority Lock is now required before page implementation.
- New canonical routes/screens must update authority + tests + normal navigation + evidence inventory in the same task.

### F02 — CRITICAL — Mutation safety was not a first-class UI gate

Evidence:
- Retail checkout tests explicitly enforce idempotency-key reuse across retry and simultaneous duplicate-submit blocking.
- Driver/Van finance repository contracts use idempotency keys.
- Van wallet tests assert idempotency-key submission.

Risk:
A page may be visually correct but duplicate checkout, collection, remittance, order, assignment or notification mutations after double tap/retry.

Remediation:
- Added `ui-interaction-safety.md`.
- Any state-changing UI now requires busy-state locking, server authority, retry semantics and idempotency evaluation.
- Financial/order/assignment transitions are treated as high-risk mutations.

### F03 — HIGH — Pagination/scalability was weaker than repository policy

Evidence:
- `DashboardPaginationContractTest.php` requires every admin table to have a pagination contract.
- High-growth Dashboard histories are server-paginated.
- Mobile surfaces use incremental/list-builder patterns and live polling.

Risk:
An agent can create an attractive grid that loads an unbounded dataset or a live screen that leaks timers/requests.

Remediation:
- Added `ui-performance.md`.
- Server pagination, mobile incremental rendering, search request control and polling lifecycle are explicit acceptance requirements.
- Quality-gate metadata now exposes the existing Dashboard pagination contract.

### F04 — HIGH — Accessibility was partially documented but not operational enough

Evidence:
- Dashboard production components already preserve `:focus-visible`, ARIA modal labelling, focus restoration, labelled controls and status roles.
- Customer shared components use Flutter `Semantics`.
- Driver has limited explicit semantic usage.
- No broad Van `Semantics` pattern was found during source search.

Risk:
An agent may add icon-only actions, modals or status UI that works visually but is weak for keyboard/screen-reader users.

Remediation:
- Added `ui-accessibility.md`.
- Existing Dashboard focus/ARIA behavior becomes a preservation contract.
- Flutter icon actions require tooltip/semantic meaning.
- Text scale, touch targets, non-color-only status and reduced motion are explicit.

### F05 — HIGH — Authorization and visible-navigation proof needed to be tied to page creation

Evidence:
- `AdminNavigationAuthorizationAuditTest.php` verifies visible Dashboard navigation targets for Super Admin, B2B Admin and Retail Admin.
- Customer/Driver navigation tests enforce channel/session boundaries.
- Push/deep-link exact-record authorization is already a product invariant.

Risk:
A page can be reachable visually but leak cross-role/cross-channel navigation or become an orphaned route.

Remediation:
- Route-authority and interaction-safety skills require both normal-navigation reachability and server authorization.
- Hidden action is not authorization; visible action must match permission, backend remains authoritative.

### F06 — HIGH — Visual workflow path coverage can silently skip Customer/Driver changes

Evidence:
- Web visual QA broadly watches `backend/resources/views/admin/**`.
- Mobile screenshot workflow has narrow Customer/Driver path filters while Van uses `apps/van_app/lib/**`.

Risk:
A new Customer/Driver screen can change without triggering the screenshot workflow.

Remediation:
- Existing `uiux-evidence.md` rule is retained and elevated: changed UI path must be covered by the owning visual workflow or the workflow filter is updated in the same PR.
- This remains a repository risk until path coverage is broadened or every new path is deliberately added.

### F07 — MEDIUM — Form navigation/dirty-state safety was not explicit

Risk:
Back/close/navigation can silently discard a partially completed business operation.

Remediation:
- `ui-interaction-safety.md` now requires dirty-state evaluation for meaningful create/edit/wizard flows.
- Confirmation is required only when meaningful unsaved work would be lost; do not annoy users for untouched forms.

### F08 — MEDIUM — Static UI auditor did not publish surface-specific follow-up gates

Evidence:
- `scripts/foodex-uiux-audit.py` catches measurable visual-system anti-patterns but runtime/route/mutation tests still live elsewhere.

Risk:
A worker can run the static audit without knowing which additional product tests are mandatory.

Remediation:
- Added `.ai/uiux/quality-gates.json`.
- The changed-surface audit reports required authority, compliance, interaction and evidence gates per affected surface.

### F09 — MEDIUM — Skill drift needed its own maintenance protocol

Risk:
Production tokens/routes/tests evolve while prose skills become stale.

Remediation:
- Added `uiux-skill-maintenance.md`.
- Changes to canonical theme/component/route/evidence contracts must update repository-native skill metadata in the same PR when durable behavior changed.

## Post-audit target

A future agent receiving only:

`اعمل صفحة X`

must resolve:
1. the canonical route/renderer;
2. the production design system;
3. the correct page archetype;
4. the authoritative data and permission boundary;
5. mutation/retry/idempotency behavior;
6. accessibility semantics;
7. scalability/pagination/live refresh lifecycle;
8. localization/responsive states;
9. exact runtime evidence and CI gates.

A visually convincing page that fails any of those dimensions is not a completed FOODEX page.
