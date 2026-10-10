# Skill: FOODEX UI Context Authority

Use for **every new or materially changed page/screen** before selecting a Golden Page or writing markup/widgets.

Visual consistency is not enough. The page must inherit the correct route, actor, tenant/store/channel, money, asset and lifecycle authority.

## 1. Resolve route/function authority first

Read:
- `docs/execution/UI_ROUTE_AUTHORITY.json`
- `docs/execution/FOODEX_FUNCTION_SCREEN_COVERAGE_MATRIX.md`
- the current router/navigation source for the selected surface.

Lock internally:

```text
Function / business outcome:
Surface:
Canonical route / workspace:
Canonical renderer:
Normal navigation entry:
Actor / role:
Permission:
Channel:
Store / tenant scope:
Authoritative data/service:
```

If these are unknown, the visual reference is not enough to start implementation.

## 2. Store / channel / tenant is a UI design input

Dashboard and mobile surfaces must reflect the active authorized business scope without exposing raw internal ownership controls.

Read when relevant:
- `backend/app/Support/StoreContext.php`
- `backend/tests/Feature/TenantContextTest.php`

Rules:
- never let a select/deep link/client payload widen server authorization;
- hide irrelevant actions for UX, but still enforce permission/scope server-side;
- exact-record View/Edit/Manage must re-resolve current authorized scope;
- multi-store actors need explicit, business-readable store context rather than hidden implicit switching.

## 3. Currency and numeric authority

Money display is domain output, not decoration.

Before rendering amount/balance/price/invoice/settlement:
- identify the authoritative currency source from record/config/domain service;
- preserve domain precision and rounding;
- format consistently with locale without changing numeric authority;
- do not hard-code EGP/KWD/USD merely because a nearby screenshot used it;
- do not perform ad-hoc client-side FX;
- payment/settlement success comes from backend/provider authority, never from a formatted amount or return navigation.

## 4. Brand assets and icons

Read:
- `docs/architecture/FOODEX_BRAND_SYSTEM.md`
- `docs/design-reference/ASSET_USAGE.md`

Rules:
- use the production vector/logo asset when available;
- keep one icon family per surface;
- reference screenshots are design evidence, not runtime assets;
- product/store/offer media stays server/CDN driven;
- do not bake dynamic translated UI copy into images.

## 5. Function-to-screen completion chain

A user-facing function is complete only when this chain is real:

`Function -> Authorized entry point -> Canonical route -> Real screen -> Authoritative data/service -> Executable action -> Persisted result/truthful feedback -> Related-screen reconciliation`

Use `FOODEX_FUNCTION_SCREEN_COVERAGE_MATRIX.md` as the reference model.

An API endpoint plus a pretty page is not a complete feature if navigation/action/reconciliation is missing.

## 6. Growth and lifecycle input

Before implementation classify:
- bounded vs growing list;
- static vs live data;
- polling/subscription/resume behavior;
- expected pagination/load-more;
- stale/offline behavior;
- mutation concurrency risk.

Then apply `.ai/skills/ui-performance.md` and `.ai/skills/ui-interaction-safety.md` where relevant.

## 7. Executable contract planner

Generate the implementation contract before coding:

```bash
python3 scripts/foodex-ui-contract-plan.py \
  --surface <dashboard|customer|driver|van> \
  --archetype <page-archetype> \
  [--function-id <canonical-function-id>] \
  [--route <route>]
```

The planner returns:
- route authority;
- production sources;
- Golden Page candidates;
- mandatory context inputs;
- required tests/evidence;
- applicable archetype requirements.

Use that output as the task's UI contract. Source code and authoritative contracts still outrank generated planning output.

## 8. Completion gate

PASS only if the worker can state:
- who is using the page;
- under which permission/channel/store/tenant scope;
- which canonical route/renderer owns it;
- which service/data is authoritative;
- how money/currency is sourced if present;
- which production assets/components are reused;
- which lifecycle/pagination rules apply;
- which exact tests/evidence prove it.

## Business casebook inference

When a short prompt is used, `foodex-ui-intent-plan.py` may identify a production-backed business case before this context pass.

That inference is only a starting point. Explicit Issue scope, route authority, Store/channel/tenant context and current source remain higher authority. Never widen access or invent a mutation because a keyword matched a casebook entry.

