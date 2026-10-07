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
| C01 | Sales Control uses authoritative premium Dashboard shell | #1036 | shell regression tests + runtime + owner evidence: [Commercial #1036 manifest](UIUX_V42_RECOVERY_1036_COMMERCIAL_EVIDENCE.md) | OPEN |
| C02 | Sales Control normal flow uses structured controls, not routine raw JSON/internal IDs | #1036 | tests + runtime interaction + owner evidence: [Commercial #1036 manifest](UIUX_V42_RECOVERY_1036_COMMERCIAL_EVIDENCE.md) | OPEN |
| C03 | Break-pack unit is an authoritative Selling Unit lookup | #1036 | lookup tests + runtime + owner evidence: [Commercial #1036 manifest](UIUX_V42_RECOVERY_1036_COMMERCIAL_EVIDENCE.md) | OPEN |
| C04 | Flash Offers live under Marketing/Promotions and Create/Edit is Wizard/Modal/business workflow | #1036 | route/interaction tests + runtime + owner evidence: [Commercial #1036 manifest](UIUX_V42_RECOVERY_1036_COMMERCIAL_EVIDENCE.md) | OPEN |
| C05 | Flash audience Customer/Group/Region/Route uses lookups/multi-select, not JSON entry | #1036 | tests + runtime + owner evidence: [Commercial #1036 manifest](UIUX_V42_RECOVERY_1036_COMMERCIAL_EVIDENCE.md) | OPEN |
| C06 | Flash products use Product Builder + Selling Unit lookup; Channels use structured toggles/selectors | #1036 | tests + runtime + owner evidence: [Commercial #1036 manifest](UIUX_V42_RECOVERY_1036_COMMERCIAL_EVIDENCE.md) | OPEN |
| F01 | Van transfer target uses searchable Van lookup | #1037 | tests + runtime + owner evidence: [FieldOps #1037 manifest](UIUX_V42_RECOVERY_1037_FIELDOPS_EVIDENCE.md) | OPEN |
| F02 | Assignment representative/operator and Warehouse use authoritative lookups | #1037 | tests + runtime + owner evidence: [FieldOps #1037 manifest](UIUX_V42_RECOVERY_1037_FIELDOPS_EVIDENCE.md) | OPEN |
| F03 | Visit/route Customer, Store, Route and Order references use authoritative lookups | #1037 | tests + runtime + owner evidence: [FieldOps #1037 manifest](UIUX_V42_RECOVERY_1037_FIELDOPS_EVIDENCE.md) | OPEN |
| F04 | Address Quality Territory uses authoritative lookup, not typed territory_key | #1037 | tests + runtime + owner evidence: [FieldOps #1037 manifest](UIUX_V42_RECOVERY_1037_FIELDOPS_EVIDENCE.md) | OPEN |
| F05 | Territory create/edit is map-first with edit/move/delete point, Undo, Clear and polygon validation | #1037 | interaction tests + runtime map evidence + owner evidence: [FieldOps #1037 manifest](UIUX_V42_RECOVERY_1037_FIELDOPS_EVIDENCE.md) | OPEN |
| F06 | Field Operations grids are informative/compact and use ellipsis row actions | #1037 | visual runtime responsive + owner evidence: [FieldOps #1037 manifest](UIUX_V42_RECOVERY_1037_FIELDOPS_EVIDENCE.md) | OPEN |
| MC01 | Customer compact header/full usable viewport on all audited screens | #1038 | route inventory + runtime narrow/wide + owner evidence: [Customer #1038 manifest](UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md) | OPEN |
| MC02 | Customer Start/End/action filters remain one line | #1038 | widget/static + runtime + owner evidence: [Customer #1038 manifest](UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md) | OPEN |
| MC03 | Customer order/reference identifiers do not wrap ambiguously | #1038 | tests + runtime + owner evidence: [Customer #1038 manifest](UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md) | OPEN |
| MC04 | Customer rows/cards compact; row actions use ellipsis where applicable | #1038 | runtime visual + owner evidence: [Customer #1038 manifest](UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md) | OPEN |
| MC05 | Customer dynamic surfaces auto-refresh foreground/resume and truthfully show stale/offline; manual refresh fallback only | #1038 | runtime/state tests + owner evidence: [Customer #1038 manifest](UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md) | OPEN |
| AC01 | Customer login visibly says Customer App / تطبيق العميل | #1038 | AR/EN runtime + owner evidence: [Customer #1038 manifest](UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md) | OPEN |
| AC02 | Customer Remember Me + biometric secure-session behavior remains valid | #1038 | tests + runtime + owner evidence: [Customer #1038 manifest](UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md) | OPEN |
| IC01 | Customer real invoice visibly includes configured FOODEX/company identity/logo and authoritative invoice fields/totals | #1038 | backend/widget tests + AR/EN runtime + owner evidence: [Customer #1038 manifest](UIUX_V42_RECOVERY_1038_CUSTOMER_EVIDENCE.md) | OPEN |
| MD01 | Driver compact/full-width data-first layout across audited screens | #1039 | route inventory + runtime + owner evidence: [Driver #1039 manifest](UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md) | OPEN |
| MD02 | Driver one-line filters/no-wrap identifiers/compact rows/ellipsis actions | #1039 | tests + runtime + owner evidence: [Driver #1039 manifest](UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md) | OPEN |
| MD03 | Driver dynamic surfaces auto-refresh + stale/offline truthfulness | #1039 | state tests + runtime + owner evidence: [Driver #1039 manifest](UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md) | OPEN |
| AD01 | Driver login visibly says Driver App / تطبيق السائق | #1039 | AR/EN runtime + owner evidence: [Driver #1039 manifest](UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md) | OPEN |
| AD02 | Driver Remember Me + biometric secure-session behavior remains valid | #1039 | tests + runtime + owner evidence: [Driver #1039 manifest](UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md) | OPEN |
| LD01 | Foreground new-order alert has authoritative identity and direct View/Open action | #1039 | push tests + runtime + owner evidence: [Driver #1039 manifest](UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md) | OPEN |
| LD02 | Notification/deep link opens exact authoritative assignment/order instead of generic list | #1039 | navigation tests + runtime + owner evidence: [Driver #1039 manifest](UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md) | OPEN |
| LD03 | Driver duplicate events dedupe; already-open record refreshes instead of stacking alerts | #1039 | tests + runtime + owner evidence: [Driver #1039 manifest](UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md) | OPEN |
| MV01 | Van compact/full-width data-first layout across audited screens | #1040 | `van_screen_inventory.dart`; explicit 19-case `van_foundation_screen.dart`; `screenshot_evidence_test.dart` 430x932 + 360x800 AR/EN | OPEN |
| MV02 | Van one-line filters/no-wrap identifiers/compact rows/ellipsis actions | #1040 | production Routes/Visits/Catalog/Orders/finance surfaces + `app_test.dart` navigation/sales/visit tests + screenshot evidence | OPEN |
| MV03 | Van dynamic surfaces auto-refresh + stale/offline truthfulness | #1040 | lifecycle observers + stale/offline states across dashboard/routes/map/detail/customers/visits/orders/finance/notifications; `app_test.dart` | OPEN |
| AV01 | Van login visibly says Van App / تطبيق الفان | #1040 | `van_login_screen.dart` + AR/EN `screenshot_evidence_test.dart` login capture | OPEN |
| AV02 | Van Remember Me + biometric secure-session behavior remains valid | #1040 | `app.dart`, `van_auth_persistence.dart`, `van_session_store.dart`, auth widget tests + AR/EN evidence | OPEN |
| AV03 | Van application-level capability exceptions are explicitly evaluated/documented | #1040 | `apps/van_app/docs/UIUX_V42_COMPLIANCE_EVIDENCE.md` + contract/widget/backend tests | OPEN |
| Q01 | Project-wide AR/EN localization; no raw state/channel/role/type/payment/unit text where localized UI is required | #1041 | whole-tree static audit + [#1041 audit record](UIUX_V42_RECOVERY_1041_STATIC_AUDIT.md); runtime localization remains independently reviewed by #1042 | OPEN |
| Q02 | Every requirement has exactly one owner and source/test evidence; no UNKNOWN/UNOWNED row | #1041 | generated `uiux-v42-recovery-static-audit.json` + [#1041 audit record](UIUX_V42_RECOVERY_1041_STATIC_AUDIT.md) | OPEN |
| Q03 | Legacy route/screen inventory is included; diff-based guards do not grandfather violations | #1041 | whole-tree route/source inventories + [#1041 audit record](UIUX_V42_RECOVERY_1041_STATIC_AUDIT.md) | OPEN |
| V01 | Integrated Dashboard + Customer + Driver + Van real runtime evidence exists for all visual/interaction rows | #1042 | evidence manifest/screenshots | OPEN |
| V02 | Required AR/EN + RTL/LTR + responsive states have no overflow/hidden action/mixed-language failures | #1042 | reviewed runtime evidence | OPEN |
| G01 | Final integrated matrix is 100% PASS on exact recovery implementation HEAD | #1043 | independent matrix/source recheck | OPEN |
| G02 | Exact frozen SHA and evidence lineage recorded; child closure not used as substitute proof | #1043 | convergence report | OPEN |
| R01 | Next real Setup/APKs/update artifacts are built/clean-installed/published from exact #1043 frozen SHA | #1021 | release manifests + hashes + clean-install proof | OPEN |

## Owner evidence checkpoints

### #1036 — Commercial Sales Control + Flash Offers

Owner implementation evidence is recorded here before child closure. Per the mission invariant, **C01–C06 remain OPEN** until the later independent #1041/#1042 gates confirm source coverage and real runtime evidence on the integrated recovery head.

- **C01 — Sales Control authoritative Dashboard shell**
  - Source: `backend/resources/views/admin/commercial-dashboard.blade.php`; `backend/resources/views/admin/flash-offer-preview.blade.php`; `backend/resources/views/admin/flash-offer-analytics.blade.php`.
  - Tests: `CommercialDashboardContractTest::test_sales_control_uses_premium_dashboard_shell_instead_of_legacy_standalone_surface`; `::test_flash_offers_share_the_authoritative_foodex_admin_shell`; `::test_flash_preview_and_analytics_are_executable_against_canonical_flash_tables`.
  - Runtime boundary: exact-head FOODEX UI Visual QA / Web runtime evidence for PR #1047 in EN/LTR and AR/RTL, including responsive shell behavior.

- **C02 — Sales Control structured business controls**
  - Source: structured Selling Unit, Availability Window, Targeting/Quota Rule, channel, quota and close/override controls in `commercial-dashboard.blade.php`; server validation in `CommercialDashboardController::validateStructuredSalesConfiguration`.
  - Tests: Sales Control shell/structured-controls test plus `test_platform_admin_keeps_advanced_json_behind_privileged_section` and `test_sales_control_rejects_targeting_customer_outside_authoritative_store_scope`.
  - Raw JSON is transport-only for the normal store workflow; manually editable JSON is fenced behind the privileged Platform Admin Advanced section.

- **C03 — Break-pack authoritative Selling Unit lookup**
  - Source: `data-break-pack-selling-unit` selector is populated from product Selling Units and live-synchronized from the structured Selling Unit editor; controller revalidates the selected active unit.
  - Tests: `test_final_gate_configuration_persists_break_pack_audience_and_authoritative_flags` plus Sales Control structured-control coverage.

- **C04 — Flash Offers Marketing/Promotions structured Create/Edit**
  - Source: `data-flash-offer-form`, exact-offer edit loading, compact ellipsis lifecycle actions, full FOODEX Preview/Analytics shell.
  - Tests: `test_flash_offer_workspace_uses_structured_business_controls_instead_of_raw_json_or_ids`, `test_flash_offer_rows_use_one_compact_action_menu_and_catalog_localization`, Preview/Analytics executable contract test.

- **C05 — Flash Audience authoritative lookups**
  - Source: Customer / Customer Group / Region / Route multi-selects from `CommercialDashboardController::flashOfferLookups`; server scope enforcement in `validateFlashAudience`.
  - Tests: valid lookup persistence in `test_final_gate_configuration_persists_break_pack_audience_and_authoritative_flags`; negative scope coverage in `test_flash_offer_rejects_audience_values_outside_authoritative_store_lookups`.

- **C06 — Flash Product Builder + Selling Unit + structured Channels**
  - Source: `data-flash-product-builder`, authoritative per-product Selling Unit catalog/validation, Customer/Van channel checkboxes, Van parity regression.
  - Tests: `test_flash_offer_workspace_uses_structured_business_controls_instead_of_raw_json_or_ids`, `test_van_commercial_parity_is_explicit_in_sales_control_and_flash_offer_authoring`, final persistence test.

## #1035 owner evidence checkpoint

These rows intentionally remain `OPEN` until required runtime evidence is complete and later independent gates confirm them.

| ID | Current owner evidence on canonical #1035 lane |
|---|---|
| D01 | `AdminNavigation` business-domain grouping + deterministic sidebar-order regression coverage are present; Visual QA now asserts the exact 11-group business-first Sidebar order in both AR and EN before accepting Admin Hub runtime evidence. Independent runtime review still required. |
| D02 | Shared `foodex-page-header` is statically enforced across the nine #1035-owned Dashboard views, including Van Finance Support; Customer 360 exposes translated horizontal detail tabs. Visual QA performs shared-shell/overflow runtime assertions; exact-head runtime evidence is required before owner closure. |
| D03 | Order Operations preserves direct exact-order View and primary Create/New Order; Notification Center and Campaigns expose explicit per-record Edit actions. Visual QA now opens real seeded record-action menus on desktop/mobile AR+EN; exact-head runtime evidence is required before owner closure. |
| D04 | Owned hub/order/admin actions plus Notification Center/Campaigns use FOODEX green primary and white/green secondary styling with deterministic coverage; the Visual QA evidence pack captures these real actions in AR+EN. Exact-head visual evidence is required before owner closure. |
| D05 | Order Operations, Notification Center and Notification Campaigns consolidate record actions into compact green ellipsis menus with deterministic coverage. Visual QA opens Notification/Campaign menus at desktop and mobile-390 in AR+EN; exact-head evidence is required before owner closure. |
| D06 | Raw Store ID entry removed from Live Tracking; Order Operations/Reports/Customer 360 use business-facing presentation; Notifications/Campaigns use user lookups; Mobile Settings runtime/reviewer/submission workflows are structured. A deterministic cross-route audit covers all nine #1035-owned Dashboard views, including business-facing Van Finance Support, forbidding routine numeric-ID entry/raw IDs/error codes/routine JSON; Firebase Service Account JSON is the explicit privileged technical exception. Visual QA repeats the shell/raw-input checks at runtime; exact-head evidence is required before owner closure. |
| D08 | Administration sidebar collapses to one entry opening the card-based Admin Hub; Visual QA now asserts exactly one Administration child linking to `/admin/administration` in AR and EN and requires dedicated runtime screenshots. Independent runtime review still required. |
| D09 | Admin Hub and Mobile Settings expose Customer/Driver/Van as first-class apps; Visual QA asserts exactly those three cards plus per-app Preview/App Version/Settings links in AR/EN. Van Push, Store Submission, Reviewer/Test Account and the shared read-only Van Finance Support plane are independently reachable; Store Readiness validates Van Android AAB. Exact-head runtime evidence is required before owner closure. |
| D10 | Shared `foodex-admin-layout` / `foodex-admin-main` / Sidebar / page-header contract is statically enforced across all nine #1035-owned Dashboard views. Visual QA executes the same shell/no-overflow/no-routine-raw-input contract across representative owned routes including Van Finance Support; exact-head evidence is required before owner closure. |
| D11 | Live Tracking uses authorized human-readable Store lookup and a mixed Driver+Van runtime with distinct entity symbols; Browser tests cover mixed-feed merge and stale/online/offline states. Visual QA now seeds Driver stale + Van online fixtures and requires AR/EN mixed-runtime screenshots before evidence is accepted. Later independent runtime review still required. |

## Worker update rule

When an owner believes a row is complete, the PR/Issue must record the exact source/test/runtime evidence. The row remains OPEN until the owning lane has evidence and the later independent gates confirm it.

## Final gate invariant

#1043 must fail if any row is not PASS. It may not convert OPEN/PARTIAL rows to PASS merely because their owner Issue is closed.
