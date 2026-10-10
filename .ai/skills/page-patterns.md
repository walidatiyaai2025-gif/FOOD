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
