# FOODEX UI/UX v4.2 Recovery Requirement Matrix

Mission: **#1034 — UIUX-V42-RECOVERY**  
Machine-readable companion: `docs/execution/UIUX_V42_RECOVERY_REQUIREMENTS.json`

## Status semantics

- `OPEN` — owned but not yet accepted.
- `PASS` — implementation + required automated/runtime evidence complete.
- `FAIL` — known violation.
- `PARTIAL` — some subrequirements remain.
- `UNKNOWN` / `UNOWNED` — forbidden at final convergence.

**Issue closure or green CI alone never changes a row to PASS.**

| ID | Requirement | Owner | Required evidence | Initial status |
|---|---|---:|---|---|
| D01 | Sidebar organized by business domain | #1035 | source + runtime AR/EN | OPEN |
| D02 | Page header + related-function horizontal tabs in owned Dashboard scope | #1035 | source + runtime responsive | OPEN |
| D03 | Add/Create uses clear primary workflow; View/Edit/Manage opens exact record in owned Dashboard scope | #1035 | interaction tests + runtime | OPEN |
| D04 | FOODEX green/white action contract in owned Dashboard scope | #1035 | visual runtime | OPEN |
| D05 | Informative Orders-style grids + one compact ellipsis row-action menu in owned Dashboard scope | #1035 | source + runtime responsive | OPEN |
| D06 | No routine raw IDs/keys/codes/JSON in general Dashboard scope outside Commercial/FieldOps | #1035 | static audit + runtime | OPEN |
| D08 | Administration is one Sidebar entry opening a true icon/card Admin Hub | #1035 | route tests + runtime AR/EN | OPEN |
| D09 | Dashboard application administration treats Customer/Driver/Van as first-class apps | #1035 | source tests + runtime | OPEN |
| D10 | Shared FOODEX admin shell/design components; no owned legacy standalone shells | #1035 | static route audit + runtime | OPEN |
| D11 | Main Dashboard Live Tracking truthfully combines Driver + Van with distinct identity/stale state | #1035 | tests + runtime map evidence | OPEN |
| C01 | Sales Control uses authoritative premium Dashboard shell | #1036 | shell regression tests + runtime | OPEN |
| C02 | Sales Control normal flow uses structured controls, not routine raw JSON/internal IDs | #1036 | tests + runtime interaction | OPEN |
| C03 | Break-pack unit is an authoritative Selling Unit lookup | #1036 | lookup tests + runtime | OPEN |
| C04 | Flash Offers live under Marketing/Promotions and Create/Edit is Wizard/Modal/business workflow | #1036 | route/interaction tests + runtime | OPEN |
| C05 | Flash audience Customer/Group/Region/Route uses lookups/multi-select, not JSON entry | #1036 | tests + runtime | OPEN |
| C06 | Flash products use Product Builder + Selling Unit lookup; Channels use structured toggles/selectors | #1036 | tests + runtime | OPEN |
| F01 | Van transfer target uses searchable Van lookup | #1037 | tests + runtime | OPEN |
| F02 | Assignment representative/operator and Warehouse use authoritative lookups | #1037 | tests + runtime | OPEN |
| F03 | Visit/route Customer, Store, Route and Order references use authoritative lookups | #1037 | tests + runtime | OPEN |
| F04 | Address Quality Territory uses authoritative lookup, not typed territory_key | #1037 | tests + runtime | OPEN |
| F05 | Territory create/edit is map-first with edit/move/delete point, Undo, Clear and polygon validation | #1037 | interaction tests + runtime map evidence | OPEN |
| F06 | Field Operations grids are informative/compact and use ellipsis row actions | #1037 | visual runtime responsive | OPEN |
| MC01 | Customer compact header/full usable viewport on all audited screens | #1038 | route inventory + runtime narrow/wide | OPEN |
| MC02 | Customer Start/End/action filters remain one line | #1038 | widget/static + runtime | OPEN |
| MC03 | Customer order/reference identifiers do not wrap ambiguously | #1038 | tests + runtime | OPEN |
| MC04 | Customer rows/cards compact; row actions use ellipsis where applicable | #1038 | runtime visual | OPEN |
| MC05 | Customer dynamic surfaces auto-refresh foreground/resume and truthfully show stale/offline; manual refresh fallback only | #1038 | runtime/state tests | OPEN |
| AC01 | Customer login visibly says Customer App / تطبيق العميل | #1038 | AR/EN runtime | OPEN |
| AC02 | Customer Remember Me + biometric secure-session behavior remains valid | #1038 | tests + runtime | OPEN |
| IC01 | Customer real invoice visibly includes configured FOODEX/company identity/logo and authoritative invoice fields/totals | #1038 | backend/widget tests + AR/EN runtime | OPEN |
| MD01 | Driver compact/full-width data-first layout across audited screens | #1039 | route inventory + runtime | OPEN |
| MD02 | Driver one-line filters/no-wrap identifiers/compact rows/ellipsis actions | #1039 | tests + runtime | OPEN |
| MD03 | Driver dynamic surfaces auto-refresh + stale/offline truthfulness | #1039 | state tests + runtime | OPEN |
| AD01 | Driver login visibly says Driver App / تطبيق السائق | #1039 | AR/EN runtime | OPEN |
| AD02 | Driver Remember Me + biometric secure-session behavior remains valid | #1039 | tests + runtime | OPEN |
| LD01 | Foreground new-order alert has authoritative identity and direct View/Open action | #1039 | push tests + runtime | OPEN |
| LD02 | Notification/deep link opens exact authoritative assignment/order instead of generic list | #1039 | navigation tests + runtime | OPEN |
| LD03 | Driver duplicate events dedupe; already-open record refreshes instead of stacking alerts | #1039 | tests + runtime | OPEN |
| MV01 | Van compact/full-width data-first layout across audited screens | #1040 | route inventory + runtime | OPEN |
| MV02 | Van one-line filters/no-wrap identifiers/compact rows/ellipsis actions | #1040 | tests + runtime | OPEN |
| MV03 | Van dynamic surfaces auto-refresh + stale/offline truthfulness | #1040 | state tests + runtime | OPEN |
| AV01 | Van login visibly says Van App / تطبيق الفان | #1040 | AR/EN runtime | OPEN |
| AV02 | Van Remember Me + biometric secure-session behavior remains valid | #1040 | tests + runtime | OPEN |
| AV03 | Van application-level capability exceptions are explicitly evaluated/documented | #1040 | parity checklist + tests | OPEN |
| Q01 | Project-wide AR/EN localization; no raw state/channel/role/type/payment/unit text where localized UI is required | #1041 | independent static/runtime audit | OPEN |
| Q02 | Every requirement has exactly one owner and source/test evidence; no UNKNOWN/UNOWNED row | #1041 | generated coverage report | OPEN |
| Q03 | Legacy route/screen inventory is included; diff-based guards do not grandfather violations | #1041 | route/source inventory | OPEN |
| V01 | Integrated Dashboard + Customer + Driver + Van real runtime evidence exists for all visual/interaction rows | #1042 | evidence manifest/screenshots | OPEN |
| V02 | Required AR/EN + RTL/LTR + responsive states have no overflow/hidden action/mixed-language failures | #1042 | reviewed runtime evidence | OPEN |
| G01 | Final integrated matrix is 100% PASS on exact recovery implementation HEAD | #1043 | independent matrix/source recheck | OPEN |
| G02 | Exact frozen SHA and evidence lineage recorded; child closure not used as substitute proof | #1043 | convergence report | OPEN |
| R01 | Next real Setup/APKs/update artifacts are built/clean-installed/published from exact #1043 frozen SHA | #1021 | release manifests + hashes + clean-install proof | OPEN |

## #1035 owner evidence checkpoint

These rows intentionally remain `OPEN` until required runtime evidence is complete and later independent gates confirm them.

| ID | Current owner evidence on canonical #1035 lane |
|---|---|
| D01 | `AdminNavigation` business-domain grouping + deterministic sidebar-order regression coverage on PR #1044. Runtime AR/EN still required. |
| D02 | Shared `foodex-page-header` is now statically enforced across the eight audited owned Dashboard views; Customer 360 exposes translated horizontal detail tabs. Runtime/responsive proof still required. |
| D03 | Order Operations preserves direct exact-order View and primary Create/New Order; Notification Center and Campaigns now expose explicit per-record Edit actions instead of ambiguous always-open/save-labelled editing. Deterministic compliance coverage added; runtime interaction proof still required. |
| D04 | Owned hub/order/admin actions plus Notification Center/Campaigns now use FOODEX green primary and white/green secondary action styling with regression coverage. Visual runtime proof still required. |
| D05 | Order Operations, Notification Center and Notification Campaigns now consolidate record actions into compact green ellipsis menus with deterministic coverage. Broader runtime responsive proof still required. |
| D06 | Raw Store ID entry removed from Live Tracking; Order Operations/Reports/Customer 360 identifier exposure reduced; Notifications and Notification Campaigns use business-facing user lookups; Mobile Settings runtime/reviewer/submission workflows are structured with legacy JSON backend-only compatibility. A deterministic cross-route static audit now covers all eight #1035-owned Dashboard views, forbidding routine numeric-ID entry, raw internal ID labels/rendering, error/HTTP code rendering and routine JSON editors; Firebase Service Account credentials remain the explicit technical JSON exception. Runtime proof remains required. |
| D08 | Administration sidebar collapses to one entry opening the card-based Admin Hub; covered by Administration Hub tests. Runtime AR/EN still required. |
| D09 | Admin Hub and Mobile Settings expose Customer/Driver/Van as first-class apps; direct per-app Preview/App Version/Settings actions exist; Push Provider, Store Submission, and Reviewer/Test Account administration separate Van from Driver; store-submission metadata now has Van Android/iOS lanes and CI registers a dedicated Van Android release-AAB validation job. Runtime proof still required. |
| D10 | Shared `foodex-admin-layout` / `foodex-admin-main` / Sidebar / page-header contract is statically enforced across eight audited owned Dashboard views. Runtime shell audit still required. |
| D11 | Live Tracking store filtering uses authorized human-readable Store lookup; Driver/Van combined identity/stale-state acceptance still requires exact runtime map proof. |

## Worker update rule

When an owner believes a row is complete, the PR/Issue must record the exact source/test/runtime evidence. The row remains OPEN until the owning lane has evidence and the later independent gates confirm it.

## Final gate invariant

#1043 must fail if any row is not PASS. It may not convert OPEN/PARTIAL rows to PASS merely because their owner Issue is closed.
