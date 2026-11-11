# V2 #2657 / P327 — Restaurant Digital Menu Publishing Authority

- **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`; Sidebar legacy `P327` / **native Word paragraph P316** (Applications & Restaurant); parent #2503.
- **Status:** isolated implementation candidate; acceptance and exact-head CI must be verified before merge/closure.
- **Integration:** issue-scoped `test/2657-v2-restaurant-publish-authority` → `Foodex_V2` ONLY. No `main` merge, production, tag or release.

## Placement / existing route

| Contract dimension | Resolution |
| --- | --- |
| Domain / entity | Restaurant SaaS / Smart Ordering menu revision |
| Role / actor | Authorized B2C restaurant store operator (typically `B2C_STORE_ADMIN`), with effective `smart_ordering.manage` for editing and **separate** `smart_ordering.publish` for publication |
| User goal | Review draft and publish/unpublish/schedule changes only for an authorized store |
| Workflow / frequency / density | State-changing revision approval, occasional; focused menu review rather than a separate operations grid |
| Existing parent | Restaurant SaaS / Smart Ordering canonical workspace, Digital Menu editor |
| Placement decision | **Reuse** existing Wizard/Review → Publish `WORKFLOW_STEP`; no new Sidebar link, screen, route or permission registry |
| Canonical GET | `admin.smart-ordering.menu.index` (`/admin/smart-ordering/menu`, `review=1` or `setup=publish`) |
| Canonical POST | `admin.smart-ordering.menu.publish`, `admin.smart-ordering.menu.schedule`, `admin.smart-ordering.menu.unpublish` |
| Renderer and server authority | `admin.smart-ordering-menu` / `admin._smart-ordering-launch-wizard`; `SmartOrderingMenuAdminController::context`, `RestaurantSaasStoreAccess::allowedStoreIds` and `SmartOrderingMenuRevisionService` |
| Normal navigation | Authorized `smart_ordering` restaurant Sidebar/business workspace → Digital Menu tab → Review Draft → Publish |
| Navigation type / owner | `WORKFLOW_STEP` within Restaurant SaaS; no duplicate Sidebar item |
| Permission and scope | Store-scoped manage vs publish; explicit user DENY takes precedence; Retail channel + Restaurant business profile + enabled `STORE:smart_ordering` entitlement; no link-only grant |
| States | Existing draft / published / scheduled / validation error; disabled publish actions when prices invalid or schedule pending; unauthorized action hidden and direct HTTP rejects |
| Locale, direction, responsive, a11y | Existing Arabic RTL / English LTR; same canonical FOODEX shell and mobile responsive forms; native form/button semantics, shared focus/touch patterns |
| Back / fallback | Back to editor or existing Smart Ordering Settings tab; no dead standalone publish destination |

## Fixed mismatch

The Guided Setup already checks `$canPublishSetup && $canManageSetup`, but the legacy Review step displayed Publish, Schedule and Unpublish forms to **manage-only** users (e.g. effective store-specific `smart_ordering.publish` DENY). Those controls were misleading: direct HTTP correctly rejected them. The minimal change wraps the three legacy mutating forms in the already computed `$canPublishSetup` guard. Existing read-only public-link/review state, scheduled notice and back/navigation controls remain visible. Real authorization in the controller/domain is unchanged. No permission elevation, new route, schema, API, state transition or automatic publication.

## Acceptance and negative evidence

`backend/tests/Feature/V2RestaurantPublishAuthorityTest.php` exercises real Laravel HTTP and persisted revision state:

1. Arabic/RTL and English/LTR Review rendered to a store actor with `manage=allow` but `publish=deny`; no Publish/Schedule/Unpublish HTML forms, while the separate allowed store continues to offer publication.
2. Cross-store and effective `publish` DENY block direct publish/schedule/unpublish requests; baseline revision statuses/checksums/scheduling are unchanged for both stores.
3. Valid authorized user promotes exactly the selected store's draft into the published revision; the other store's draft/publication state remains unchanged; stale replay fails.
4. Disabled Restaurant SaaS entitlement, unassigned foreign store and wholesale-only roles fail closed on direct GET/POST.
5. The Guided Setup already enforces the same role checks; the new change does not weaken it.

Before acceptance: verify exact-head PHP syntax, Laravel feature tests and full backend, Pint, PHPStan, repository policy, FOODEX V2 isolation, AR/EN localization and MySQL/Redis packaging acceptance via the required CI workflow. Manual browser viewport/keyboard/a11y and staging UAT remain deferred only under the explicit owner V2 final gate #2503/#2750 and are **not** claimed as executed here.

## Customer / Driver / Van impact matrix

| Surface | Classification | Reason |
| --- | --- | --- |
| Dashboard | **Affected** | Legacy Restaurant menu Review display guard; existing backend route authorization and revision mutations unchanged |
| Customer Flutter | **Not affected** | No Customer API, public QR/storefront payload, identity/credit/order response or app source changed; published revision semantics unchanged |
| Driver Flutter | **Not affected** | No B2C Driver login, dispatch, order status, wallet, settlement, notifications or mobile API change |
| Van Flutter | **Not affected** | No B2B VAN-only assignments, trips, visits, inventory, fulfillment, session or API change |

B2B wholesale fulfillment remains VAN ONLY; B2C retail dispatch remains DRIVER ONLY. Backend and Dashboard only; no Flutter builds required by the changed-area CI scope.

## Required final evidence

Record actual final PR head, base `Foodex_V2`, required checks (including terminal `required-ci-gate`), merge SHA on `Foodex_V2`, and Issue closure evidence only **after** verified results. Do not confuse source/QA metadata with CI execution proof. Master #2503 remains open.
