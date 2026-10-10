# Skill: FOODEX UI/UX Mandatory Self-Audit

Run this **after implementation and before claiming any page/screen complete**.

This is a hard gate. A required item that is FAIL, UNKNOWN, NOT CHECKED or "probably fine" means the UI task is not complete.

## 1. Architecture / reuse gate

PASS only if:
- canonical application shell/navigation is reused;
- current theme/tokens are reused;
- nearest production pattern was identified;
- shared components were reused before bespoke components;
- no second sidebar/footer/navigation system was introduced;
- no page-only brand color, spacing scale, radius family or typography system was introduced;
- any new reusable primitive was added to the shared system, not hidden inside one page.

## 2. Business UX gate

PASS only if:
- page is reachable through the normal product journey when required;
- title and actions describe the business task, not internal implementation;
- one primary action is visually clear;
- related functions use the existing tab/workspace model;
- View/Edit/Manage targets the exact record;
- multiple row actions collapse to the canonical overflow/ellipsis pattern;
- forms classify fields as Lookup / Enum / Builder / legitimate free input / privileged advanced input;
- managed Master Data does not require typed DB IDs/internal keys/raw JSON;
- destructive actions have deliberate confirmation and backend authorization;
- after mutation, the visible workspace is refreshed/reconciled.

## 3. Data truthfulness gate

PASS only if:
- production UI binds real authoritative services/data;
- no prototype/sample/fake "live" records entered runtime paths;
- loading is distinct from empty;
- error is explicit;
- stale/offline retained data is visibly labelled;
- maps do not fabricate coordinates;
- charts do not invent finance/stock/pricing truth in client code;
- status/amount/assignment/order transitions remain backend-authoritative.

## 4. Density / responsive gate

### Dashboard
Verify relevant widths against current contract:
- desktop ~1280+;
- compact desktop ~1024;
- tablet ~768;
- mobile ~390 / below 768.

PASS only if:
- no page-level accidental horizontal overflow;
- intentional table/tab overflow is contained;
- header/actions reflow deliberately;
- touch targets remain usable;
- grids/tables preserve useful information.

### Mobile
Verify normal portrait around 430×932.
Van also verifies compact 360×800; use compact widths for Customer/Driver when the changed interaction is density-sensitive.

PASS only if:
- header/title does not consume excessive data space;
- full usable viewport is used;
- normal records remain scannable;
- Start/End/action filters remain one logical line where applicable;
- order/reference identifiers do not wrap ambiguously;
- row actions remain compact;
- no clipped/hidden primary action.

## 5. Localization / direction gate

Verify both:
- Arabic RTL;
- English LTR.

PASS only if:
- same information hierarchy exists in both;
- no untranslated system labels leak across locale;
- status/state/channel/role/type/payment/unit values are localized;
- long translations do not break actions/tabs/rows;
- directional icons/controls/navigation make sense in both directions;
- no separate divergent Arabic-only layout is maintained.

## 6. Accessibility / interaction gate

Use the existing platform patterns.

PASS only if relevant:
- Dashboard keyboard focus remains visible (`:focus-visible` contract preserved);
- interactive targets respect current 44px Dashboard/Customer-Driver and 48px Van conventions where the theme specifies them;
- icon-only actions expose a tooltip/semantic label;
- Customer motion respects `CustomerUiMotion.resolve` / reduced-motion behavior when adding animation;
- important status is not conveyed only by color;
- dialogs/sheets have an obvious close/cancel path;
- loading does not permanently trap interaction.

## 7. Surface-specific hard gates

### Dashboard
- shared FOODEX shell;
- green/white action contract;
- informative Orders-style grid when tabular;
- one ellipsis row menu;
- authoritative lookups;
- Admin Hub/map/visualization contracts when in scope.

### Customer
- UI V3 tokens/components;
- persistent footer semantics preserved;
- compact V3 hierarchy;
- real commerce/account data;
- shared V3 states;
- order/tracking truthfulness.

### Driver
- operational data first;
- no-wrap reference;
- compact single-line filter pattern;
- green overflow actions;
- refresh/resume/offline-stale semantics;
- exact authoritative push/deep-link target when in scope.

### Van
- `VanFoundationScreen`/inventory respected;
- `FoodexVanTokens` and shared actions;
- 19-screen architecture not silently replaced;
- route/location truthfulness;
- authoritative commercial/finance state;
- Dashboard capability parity evaluated.

## 8. Route authority hard gate

Follow `.ai/skills/ui-route-authority.md`.

PASS only if:
- the function resolves to one canonical route/renderer;
- no duplicate production screen/route was introduced;
- normal navigation reaches it when routine access is required;
- role/store/channel boundaries are enforced by current authority/navigation logic;
- deep links land on the canonical authorized record/surface;
- route/navigation tests and authority metadata are updated when the canonical contract changes.

## 9. Mutation safety hard gate

For any create/edit/delete/submit/transition/finance action, follow `.ai/skills/ui-interaction-safety.md`.

PASS only if:
- simultaneous duplicate submit is prevented;
- the action has a clear busy/error/success state;
- server authority remains decisive;
- retry/idempotency semantics are correct for the operation;
- meaningful destructive actions confirm scope/impact;
- successful mutation refreshes/reconciles the visible authoritative record;
- offline/stale state cannot silently execute unsafe business mutations.

## 10. Performance / scalability hard gate

Follow `.ai/skills/ui-performance.md`.

PASS only if applicable:
- Dashboard tables satisfy the repository pagination contract;
- growing data is server-paginated or intentionally bounded;
- mobile long lists render incrementally;
- server-backed search does not create uncontrolled request storms;
- older async results cannot overwrite newer criteria;
- polling/subscriptions stop when the page no longer needs them;
- resume/timer/manual refresh do not stack duplicate refresh loops;
- the UI data shape does not introduce a known N+1.

## 11. Automated regression gate

Run the relevant existing guards; do not rely only on visual inspection.

From repository root when v4.2 UI contracts are affected:

```bash
python scripts/uiux-v42-recovery-audit.py --report uiux-v42-recovery-static-audit.json
```

Dashboard changed area, from `backend/`:

```bash
php artisan test tests/Feature/AdminNavigationAuthorizationAuditTest.php
php artisan test tests/Feature/DashboardPaginationContractTest.php
php artisan test tests/Feature/DashboardUiComplianceTest.php
php artisan test tests/Feature/AdminFilterActionVisualContractTest.php
```

For premium Dashboard work also run:

```bash
php artisan test tests/Feature/PremiumDashboardAcceptanceTest.php
```

Customer examples, from `apps/customer_app/`:

```bash
flutter test test/customer_route_authority_test.dart
flutter test test/customer_navigation_test.dart
flutter test test/customer_ui_v3_design_system_test.dart
flutter test test/customer_orders_ui_v3_test.dart
flutter test test/screenshot_evidence_test.dart
```

Driver operational examples, from `apps/driver_app/`:

```bash
flutter test test/navigation_test.dart
flutter test test/driver_active_journey_test.dart
flutter test test/screenshot_evidence_test.dart
```

Van visual/runtime evidence, from `apps/van_app/`:

```bash
flutter test test/app_test.dart
flutter test test/screenshot_evidence_test.dart
```

Choose additional feature-specific tests based on changed code.

## 12. Evidence gate

After static/widget tests, follow `.ai/skills/uiux-evidence.md`.

A screenshot proves only the state shown. It does not prove authorization, mutation correctness, stale handling or hidden interactions. Pair visual evidence with functional tests.

## 13. Completion verdict

Use exactly one internal verdict:

- **PASS** — all applicable hard gates verified.
- **FAIL** — one or more requirements violated.
- **BLOCKED** — an external dependency prevents proof.
- **UNKNOWN** — evidence not collected.

Only **PASS** permits "UI complete".

Do not convert UNKNOWN to PASS because the page looks reasonable or generic CI is green.

## 14. Executable changed-surface policy gate

The mandatory static gate is:

```bash
python3 scripts/foodex-uiux-audit.py \
  --base <base-sha> \
  --head <head-sha> \
  --report artifacts/foodex-uiux-audit.json
```

It validates the machine-readable policy under `.ai/uiux/` and rejects measurable regressions such as page-local Dashboard colors, default Bootstrap primary styling, visible raw `*_id` inputs, feature-local mobile color systems, feature-local themes, and parallel mobile navigation shells.

The report's score is **static compliance only**. Runtime visual/interaction acceptance is still governed by `uiux-evidence.md`.

## 12. Context authority gate

PASS only if the implementation contract resolves:
- canonical route/renderer and normal navigation;
- actor + permission;
- channel + Store/tenant scope;
- authoritative data/service;
- currency source/precision for money-bearing UI;
- brand/asset policy;
- lifecycle/pagination classification;
- required surface tests/evidence.

Use:

```bash
python3 scripts/foodex-ui-contract-plan.py --surface <surface> --archetype <archetype> [--function-id <id>] [--route <route>]
```

The generated contract is planning evidence, not a replacement for source/runtime proof.

