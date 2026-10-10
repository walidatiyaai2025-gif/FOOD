# Skill: FOODEX Page Patterns — Production Recipes

Use after `.ai/skills/foodex-uiux.md` and the matching surface skill. These recipes convert short page requests into the current production UI/UX composition.

## Rule zero — clone composition, not fake data

Choose the nearest current FOODEX page/screen and reuse its composition primitives. Keep the new page's real route, permissions, services and data. Never copy prototype/sample records into runtime.

## Dashboard: management/list page

Canonical composition:

```text
foodex-admin-layout
  -> foodex-admin-main / foodex-admin-page
  -> foodex-page-header
       -> title + short business subtitle
       -> foodex-header-actions
  -> foodex-tabs (only sibling domain functions)
  -> foodex-ops-shell
       -> foodex-ops-toolbar
       -> foodex-table-wrap
            -> foodex-ops-grid
                 -> localized status badge
                 -> foodex-ops-actions / foodex-ops-menu
       -> foodex-pagination
  -> foodex-state / foodex-ops-state
```

Required behavior:
- useful business columns, not IDs;
- compact search/filters;
- one record overflow menu;
- exact View/Edit/Manage/Delete target;
- table wrapper may intentionally scroll; page itself must not overflow horizontally.

## Dashboard: create/edit page

Prefer a focused modal/dialog/drawer or the domain's current edit surface.

Use:
- `.foodex-form` or `.foodex-premium-auto-form`;
- canonical controls;
- Lookup/Enum/Builder instead of internal IDs/JSON;
- one clear primary save action;
- explicit validation/error feedback;
- return to the prior working context after save when practical.

## Dashboard: detail/manage

Use:
- direct record context;
- `.foodex-operational-dialog-host .foodex-modal` for wider operational detail;
- `.foodex-ops-detail-grid` for key/value sections;
- exact actions for the selected record.

Do not send View/Edit to a generic records list.

## Dashboard: analytics/KPI

Use the premium contract:
- four equal-weight KPI cards where the dashboard contract calls for them;
- primary Orders & Revenue region roughly two-thirds;
- secondary distribution region roughly one-third;
- lower operational cards;
- shared visualization CSS/components;
- semantic FOODEX colors;
- authoritative service-calculated values.

## Customer app: commerce/list

Use:
- Customer V3 theme/tokens;
- persistent footer shell;
- compact curved header/search where appropriate;
- V3 category/product primitives;
- shared skeleton/state view;
- compact mobile density.

Order/invoice/reference must not wrap ambiguously.

## Customer app: detail/transaction

Show:
- exact record reference;
- localized authoritative status;
- last update/refresh if dynamic;
- lifecycle/timeline when the domain owns one;
- one clear next action;
- error/stale/offline truthfully.

Do not invent live vehicle tracking or payment success.

## Driver app: operational journey

Follow `driver_active_journey.dart`:
- one-line filters;
- compact assignment/order cards;
- exact identity/status;
- green ellipsis overflow for multiple actions;
- focused bottom sheet/dialog for transitions/details;
- resume/poll refresh behavior;
- stale retained data clearly marked.

## Van app: field operation

Plug into `VanFoundationScreen` and frozen inventory:
- existing AppBar/navigation shell;
- compact page body;
- `FoodexVanTokens`;
- `VanActionButton` / `VanIconAction`;
- real field-operation data;
- route/customer/order/wallet workflows remain connected;
- no fabricated coordinates or balances.

## Universal acceptance

Every new page must prove:
- correct normal navigation;
- existing shell/theme reused;
- real data source;
- localized labels/statuses;
- AR/RTL and EN/LTR;
- loading;
- empty;
- error;
- stale/offline when applicable;
- responsive target widths;
- exact-record actions;
- no raw IDs/JSON in normal business UI;
- no fake/sample live data;
- required runtime/visual evidence.

## Dashboard: tabbed operational workspace

Use when one business domain owns several sibling functions.

Composition:
- canonical shell;
- one compact page header;
- horizontal `.foodex-tabs`;
- active tab preserves the business context/filters when practical;
- one toolbar per active workspace, not duplicated toolbars for hidden tabs;
- active content uses the canonical grid/card/map recipe;
- create/edit/manage stays focused on the selected function.

Do not create five unrelated sidebar entries when the functions are siblings of one domain.

## Dashboard: lookup-heavy create/edit workflow

Use for Driver/Van/Customer/Store/Warehouse/Product/Selling Unit/Territory/Route/Order relationships.

Rules:
- searchable authoritative selector;
- business label/context visible;
- internal ID hidden behind the control;
- invalid/out-of-scope selection rejected server-side;
- optional "Add new" opens the authoritative create flow if permission allows;
- save returns to current context and refreshes the affected record/grid.

Never downgrade to a numeric ID input to save implementation time.

## Dashboard: geography / territory editor

Use map-first composition:
- large usable map;
- authoritative parent/context lookups;
- draw polygon;
- move/edit/delete point;
- Undo;
- Clear;
- validation before save;
- existing/adjacent areas when useful;
- explicit missing/invalid geometry state.

Raw GeoJSON/internal territory keys are privileged technical data, not the normal business UI.

## Dashboard: finance / invoice operations

Composition:
- finance/invoice domain shell;
- tabs for sibling finance functions;
- compact filters;
- business-readable invoice/customer/order references;
- localized settlement/payment status;
- exact invoice/order manage action;
- authoritative totals/allocations/ledger semantics;
- one ellipsis action menu per row;
- no destructive deletion of immutable ledger history through ordinary UI cleanup.

## Dashboard: Admin Hub / settings

Administration uses one sidebar entry -> card/icon Admin Hub.

Settings page:
- groups related settings clearly;
- uses structured controls;
- shows technical JSON/credentials only in explicitly privileged advanced context;
- Customer/Driver/Van administration remains first-class where applicable.

## Customer: catalog/search page

Composition:
- existing Customer shell/footer;
- compact branded header/search;
- category/product V3 primitives;
- compact filters;
- skeleton while loading;
- V3 empty/error state;
- real availability/pricing;
- product detail opens exact product;
- cart/favorite actions use current shared commerce behavior.

## Customer: orders/invoices list

Composition:
- compact header;
- one-line date/status filters;
- compact scannable rows/cards;
- no-wrap order/invoice reference;
- localized status;
- one overflow menu where several actions exist;
- pagination/load-more through current API contract;
- stale/offline indication when retained records are no longer freshly confirmed.

## Customer: order/invoice detail

Show:
- exact business reference;
- authoritative status;
- seller/customer context as applicable;
- items/totals/payments from real API semantics;
- last update/refresh for live order surfaces;
- lifecycle/timeline where owned;
- one clear next action;
- no fabricated vehicle tracking/payment result.

## Driver: delivery/proof workflow

Use focused transition UI:
- exact assignment/order;
- current allowed transition only;
- required note/proof fields;
- camera/upload state where proof is required;
- busy/error/retry;
- idempotent backend transition;
- authoritative record refresh after success;
- no multiple duplicate lifecycle buttons across a list row.

## Driver: wallet/earnings/settlement page

Use:
- Driver theme;
- compact period filter;
- server-authoritative totals;
- localized settlement status;
- concise transaction rows;
- exact related order/assignment reference;
- loading/empty/error/stale;
- no locally invented finance totals.

## Van: routes / visits

Use:
- existing Van foundation shell;
- compact route/customer/visit identity;
- current route/visit lifecycle;
- map only for authoritative coordinates;
- missing coordinate explanation;
- one focused next action;
- stale/offline truthfulness;
- no local fake completion reason.

## Van: catalog / order builder

Use:
- canonical Product/Selling Unit/pricing/offer rules;
- compact searchable catalog;
- authoritative availability;
- draft/order review as separate focused steps when current journey defines them;
- server revalidation before submit;
- no local price override masquerading as authoritative price.

## Van: wallet / collection / receipt / remittance

Use:
- authoritative custody/ledger values;
- exact customer/order/payment references;
- one clear financial mutation at a time;
- confirmation and resulting receipt/remittance state;
- explicit offline/error;
- reconcile from backend after mutation;
- never fabricate balance or mark settlement complete locally.

## Universal anti-pattern list

A FOODEX page fails review if it introduces any applicable pattern below without an explicit approved exception:
- standalone shell inside a product that already has a shell;
- page-local brand palette;
- arbitrary new radius/spacing scale;
- raw Bootstrap-looking Dashboard actions;
- raw Material defaults that ignore the app theme;
- typed DB IDs/internal keys for normal business users;
- editable raw JSON for normal business workflow;
- multiple large row action buttons instead of overflow;
- View/Edit action that dumps the user on a generic list;
- giant mobile header/padding that hides working data;
- vertically stacked Start/End/action filters where the mobile contract requires one line;
- wrapped/ambiguous order reference;
- silent stale/offline data;
- fake map coordinates;
- fake finance/chart values;
- fake prototype records in production;
- untranslated raw status/state/channel/role/type/payment/unit;
- a screenshot from an older relevant SHA used as current proof.

## Pattern completion rule

After choosing a recipe, run:
- `.ai/skills/uiux-audit.md`;
- `.ai/skills/uiux-evidence.md`.

A recipe is a build guide; the audit/evidence gate decides whether the implementation is actually accepted.
