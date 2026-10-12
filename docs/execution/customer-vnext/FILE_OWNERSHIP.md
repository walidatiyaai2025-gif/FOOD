# Customer VNext File Ownership

Issue: #1324  
Parent: #1323

This map is the collision fence for #1324-#1332. If a lane needs a file owned by another active lane, it waits for that owner to merge, rebases, and then adds only its remaining delta.

## Shared Customer foundation — #1324 only

- `apps/customer_app/lib/core/theme/customer_ui_v3_tokens.dart`
- `apps/customer_app/lib/core/theme/foodex_theme.dart`
- `apps/customer_app/lib/shared/customer_ui_v3/**`
- `apps/customer_app/lib/shared/customer_persistent_footer.dart`
- `apps/customer_app/lib/core/routing/customer_route_authority.dart`
- shared route/navigation contracts and their foundation tests
- this Screen Registry and ownership map

No other lane creates a second palette, ProductCard, footer, header system, generic state view, spacing scale, typography scale, or route authority.

## Route lanes

- #1325: existing Shopping Home surfaces under `features/retail/customer_ui_v3/` and home-specific composition only.
- #1326: catalog/category/search/product-detail feature files and route-local tests/evidence.
- #1327: cart/checkout/payment/order-success feature files and route-local tests/evidence.
- #1328: customer orders/order-detail/tracking feature files and route-local tests/evidence.
- #1329: customer account/profile/addresses/notifications/offers/settings/auth-session secondary surfaces and route-local tests/evidence.

The monolithic renderer files `features/retail/retail_customer_journey_screen.dart`, `features/storefront/multistore_design_screen.dart`, and `features/b2b/b2b_journey_screen.dart` are collision-sensitive. A lane should prefer editing the route-specific child screen/component it already dispatches to. If a dispatch change is unavoidable, only one lane edits that renderer at a time; later lanes rebase after the first merge.

## Data / Dashboard lanes

- #1330: sanitized fixture/seed/data-source/image-quality pipeline under backend seed/test assets; no Customer shared UI.
- #1331: protected System Settings reset/reseed service/controller/UI/tests. It consumes #1330's seed contract.
- #1332: production preflight + owner-controlled Launch gate service/controller/UI/tests. It aggregates evidence and does not duplicate reset logic.

Dashboard route, navigation, localization, or shared-brand files are shared infrastructure. #1331 owns the first required shared registration change; #1332 rebases on it before touching the same shared file.

## Final gate

#1333 owns independent certification only after #1324-#1332 are integrated. It must not become an implementation backdoor for unfinished child scope.
