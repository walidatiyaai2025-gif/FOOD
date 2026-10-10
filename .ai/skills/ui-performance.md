# Skill: FOODEX UI Performance, Pagination & Live Lifecycle

Use for lists, grids, search, catalogs, histories, analytics, maps and any live/polling screen.

## 1. Dashboard pagination is a repository contract

Existing regression:
- `backend/tests/Feature/DashboardPaginationContractTest.php`

Every admin table must have an explicit pagination contract.

For growing datasets:
- paginate server-side;
- preserve filters/query state;
- use independent page parameters for multiple paginators on one page;
- do not render all historical rows into one HTML table.

A small bounded reference list may be explicitly pagination-exempt according to the existing contract.

## 2. Mobile list rendering

For long lists:
- use lazy/incremental list/grid builders;
- paginate/load-more from the API where the backend supports it;
- do not eagerly build hundreds of heavy cards;
- preserve scroll/context after safe refresh where practical.

## 3. Search/filter request behavior

For server-backed search:
- do not fire uncontrolled network requests on every keystroke;
- use submit/search intent or a suitable debounce when live search is required;
- ignore/cancel stale responses so an older request cannot overwrite newer criteria;
- keep filters visible/understandable while loading;
- preserve the active query in pagination/load-more.

## 4. Live refresh / polling

A live timer/subscription must:
- start only while the surface needs it;
- stop/dispose when page/app state no longer needs it;
- refresh on resume when current FOODEX contract requires it;
- avoid stacking duplicate timers/subscriptions;
- retain last-confirmed data only with explicit stale/offline state;
- avoid polling while a conflicting authoritative mutation is unresolved.

Current Driver tests explicitly verify timer disposal and stale retention on refresh failure.

## 5. Race control

For asynchronous loads:
- identify which request result is current;
- an old slower response must not replace a newer filter/record result;
- do not set state after disposal;
- avoid duplicate refresh storms from resume + timer + manual refresh firing together.

## 6. Images/maps/charts

- reuse existing image/network caching behavior;
- size images to the rendered need; avoid full-resolution decode for tiny thumbnails when the platform abstraction supports resizing;
- do not recreate map/chart instances unnecessarily;
- charts receive service-calculated values and remain compact;
- large maps/lists should not render unnecessary off-screen detail.

## 7. Backend query implications

A UI change that adds columns/lookups/filters may require backend query review:
- eager-load intentional relationships;
- avoid N+1;
- add justified indexes for high-cardinality filters;
- do not move authoritative aggregation into browser/Flutter just to avoid server work.

## 8. Performance acceptance

For a high-growth or live screen, PASS requires:
- bounded/paginated data;
- controlled request frequency;
- timer/subscription lifecycle cleanup;
- race-safe refresh;
- explicit loading/stale states;
- no known N+1 introduced by the UI data shape.

Performance is not proven by a screenshot.
