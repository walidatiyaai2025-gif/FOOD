# V2 #2659 — P329 Digital Orders: selected Restaurant store authority

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P329, Applications & Restaurant. Parent #2503. **Delivery target:** `Foodex_V2` only.

## Product / placement decision

**Reuse** the existing Restaurant SaaS Operations workspace and its **Orders** tab:
`admin.smart-ordering.index` → shared Restaurant workspace Operations tab → `admin.smart-ordering.operations.index?tab=orders&store_id=<authorized>`.
The Restaurant Management launcher already exposes `customer_orders` and `orders` using the same canonical route. Do not create a second digital-order engine, unrelated ERP page, Sidebar node, or authorization bypass. Existing AR/EN translations, RTL/LTR, focusable tabs, shared Dashboard shell and mobile-browser responsive launcher are retained.

## Verified bug and correction

Before this change, `SmartOrderingOperationsController::index` loaded `RestaurantSaasStoreAccess::allowedStoreIds` but did not validate or honor the caller's selected `store_id`. For a user assigned several stores, the requested store could be wrong/foreign/disabled while the page silently returned the *other* accessible store(s). This was not an acceptable selected-store contract for P329.

The HTTP GET now validates positive integer `store_id`. If supplied, it must belong to the canonical allowed Retail store IDs after server-side `orders.view`, Restaurant profile and `STORE:smart_ordering` master entitlement checks. An unowned/disabled store selection produces 404. An allowed selection narrows **all subsequent orders, branches, store picker and operational read models** to that one store. Omitting `store_id` keeps the authorized multi-store aggregate behavior. Payments tab retains `finance.view` authority.

The existing Operations tab links, Orders/Payments filters and reset actions now **retain the validated `store_id` and explicit `support_access` context**, so navigating between operations tabs does not silently return to a multi-store aggregate or drop the support gate. These are existing links and forms, with no new UI text or CSS.

The shared Restaurant workspace's `$restaurantCanOrders` is now a read-only presentation projection via existing `RestaurantSaasStoreAccess::canNavigateToStore($actor, selectedStore, 'orders.view', supportAccess)` instead of broad global permission. The launcher (customer orders, orders, KDS etc.) inherits the same flag. Real server-side authorization remains in the controller; no UI flag can ever grant access. A Super Admin must enter an explicit Retail support context.

## Acceptance / regression

`backend/tests/Feature/V2RestaurantDigitalOrdersStoreScopeTest.php` covers:

1. Real authenticated HTTP `tab=orders` responses for authorized Retail stores in **AR/RTL** and **EN/LTR** with shared tab/launcher, only the requested store in `stores`, and selected-store context preserved in tab links and filter forms.
2. Foreign unassigned Retail store `store_id` returns 404, no digital-orders navigation entry.
3. Assigned but Restaurant SaaS master-disabled store returns 404, while the other enabled store is still reachable and listed.
4. Mixed B2B global role + B2C store role with explicit scoped `orders.view` DENY cannot bypass the denied store nor see its link; a separately allowed store continues to work.
5. Super Admin without `support_access=1` receives 403, while the explicitly entered support context reaches the real canonical route.
6. Existing canonical Orders/Payments split and no new public or mobile API contract.

## Surface / permission impact

| Surface | Impact | Evidence |
| --- | --- | --- |
| Laravel Dashboard (desktop / responsive mobile browser) | **Changed:** selected-store HTTP scope enforcement + consistent page/tab/launcher visibility | P329 Laravel feature tests, canonical existing AR/EN/RTL-LTR shared components |
| Customer Flutter | None | Existing order/customer API is unchanged |
| Driver Flutter | None | Driver dispatch, fulfillment and API unchanged |
| Van Flutter | None | Wholesale Van-only fulfillment and API unchanged |

No new endpoint, migration, data write, permission code, localization key, Flutter patch, or second Sidebar root; no release artifact change. B2B routes stay isolated; B2C/DRIVER and B2B/VAN policies untouched.

## Gates and handoff

Exact PR HEAD must have FOODEX V2 Isolation, Repository Policy, full Backend/Laravel, AR/EN and final Required CI Gate GREEN before merge **exclusively into Foodex_V2**. PR merge and closed source-scope issue do **not** constitute staging/UAT or production acceptance. Per owner #2503, manual staging/UAT remains deferred to the consolidated final V2 gate. No `main` merge, production deploy or release/tag.
