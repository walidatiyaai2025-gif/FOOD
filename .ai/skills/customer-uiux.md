# Skill: FOODEX Customer App UI/UX

Use for Flutter Customer surfaces: Platform/account, Retail/B2C and Wholesale/B2B.

## 1. Authoritative current sources

Read before editing:
- `apps/customer_app/lib/core/theme/customer_ui_v3_tokens.dart`
- `apps/customer_app/lib/core/theme/foodex_theme.dart`
- `apps/customer_app/lib/shared/customer_ui_v3/customer_components.dart`
- `apps/customer_app/lib/shared/customer_ui_v3/customer_states.dart`
- `apps/customer_app/lib/shared/customer_persistent_footer.dart`
- `docs/design-reference/CUSTOMER_UIUX_APPROVED_TARGET.md`
- current screen implementation in the same feature

The owner-approved mockup is a visual reference **inside the current real Customer journey**. Production routes/backend behavior remain authoritative.

## 2. Customer UI V3 palette — current production snapshot

Re-read token source before coding.

- Deep Green `#00452F`
- Deep Green Strong `#003C2A`
- Deep Green Soft `#0A5B40`
- Lime `#9BE252`
- Lime Soft `#E7F8D7`
- Mint `#EDF7F1`
- Mint Strong `#C5E0CC`
- White `#FFFFFF`
- Ink `#17231D`
- Ink Soft `#34453C`
- Muted `#68766E`
- Border `#DDE8E1`
- Success `#1F8A4C`
- Warning `#F59E0B`
- Info `#3B82F6`
- Destructive `#E5484D`

Never introduce per-screen brand colors when a token exists.

## 3. Customer spacing / radius / motion

### Spacing tokens
- 4, 8, 12, 16, 20, 24, 32, 36
- page spacing = 16

### Radius
- 12 / 16 / 22 / 30
- curved header = 38
- pill = 999

### Elevation
- card = 1
- floating = 8
- use shared card shadow

### Motion
- 120 / 180 / 240 ms
- respect reduced-motion/accessibility via `CustomerUiMotion.resolve`

Do not hard-code arbitrary spacing/radius values without a concrete reason.

## 4. Customer typography

Production uses Alexandria through `CustomerUiTypography` where configured.

Current hierarchy:
- headline large 26 / w800
- headline medium 22 / w800
- headline small 20 / w800
- title large 18 / w800
- title medium 15 / w700
- title small 14 / w700
- body large 15 / w500
- body medium 14 / w500
- body small 12 / w500
- labels 13/12 / w700

Use weight/hierarchy before making text dramatically larger. Customer v4.2 is data-first, not oversized-header UI.

## 5. Reuse the real V3 primitives

Before making a new primitive, inspect/reuse current shared components such as:
- `CustomerCurvedHeaderSurface`
- `CustomerSearchPill`
- `CustomerBadge`
- `CustomerOutlineIconButton`
- `CustomerCategoryTile`
- `CustomerProductImage`
- `CustomerProductCard`
- `CustomerSkeletonBox`
- `CustomerCategorySkeleton`
- `CustomerProductCardSkeleton`
- `CustomerStateView`
- `CustomerPersistentFooterShell`
- `CustomerPersistentFooterDock`
- `CustomerPersistentFooter`

Use real existing action widgets/flows where applicable instead of recreating login/cart/checkout behavior.

## 6. Current theme behavior

Current theme already defines:
- deep-green AppBar;
- white foreground;
- AppBar height 58;
- NavigationBar height 74;
- deep-green FilledButton;
- button height around 52;
- 44x44 minimum text-button touch target;
- pill-shaped inputs;
- lime focused input border;
- white cards over mint background.

A new page should look native to this system without manually styling every widget.

## 7. Information architecture — do not invent navigation

Preserve current real Customer concepts/routes.

### Retail persistent destinations
- Home
- Products
- Cart
- Orders
- Profile/Account

### Wholesale persistent destinations
- Home
- Shopping/Products
- Orders
- Invoices
- More/Account

Use `customer_persistent_footer.dart`; do not create another bottom-navigation model for a one-off page.

## 8. Compact mobile v4.2 rules

Mandatory:
- title/subtitle extremely compact; working data gets priority;
- use full safe viewport;
- no oversized nested cards;
- Start + End + related action stay one line where a date-range filter exists;
- if narrow, use compact controls/date-range control/horizontal scroll, not vertical filter stacking;
- Order Number / Reference never wraps ambiguously;
- compact rows/cards; more than one normal record should be scannable per screen when data permits;
- row actions use one green ellipsis overflow where multiple record actions exist;
- typography readable but space-efficient.

## 9. State model

Use shared V3 states rather than ad-hoc centered text:
- skeleton/loading;
- empty;
- error + retry;
- success when needed;
- stale/offline with last-confirmed semantics on dynamic data.

Dynamic Customer surfaces must refresh on foreground/resume according to current feature contract. Manual refresh is fallback, not the only synchronization mechanism when the domain is live.

## 10. Status / order tracking

Customer order/tracking surfaces visibly expose:
- order/reference;
- authoritative current status;
- refresh;
- last updated;
- lifecycle/timeline;
- loaded/error/empty/stale states.

Do **not** fabricate vehicle live-map tracking unless real authoritative capability exists.

## 11. Real data only

The approved prototype contains fake data. Never copy fake stores/customers/orders/invoices/mock repositories into production runtime.

Use current APIs/auth/session/commerce context/order/B2B/notification/address/payment rules.

## 12. Authentication identity

Login must visibly identify:
- `Customer App`
- `تطبيق العميل`

Remember Me + biometric flows must preserve secure-session semantics; never store plaintext password.

## 13. AR/EN / direction

- Arabic RTL and English LTR use the same hierarchy/density.
- No fake English fallback on Arabic screens.
- Do not let longer translated labels break compact layout.
- Footer destination meaning must stay identical across locales.

## 14. Customer screen Definition of Done

- current production route preserved;
- V3 tokens/theme used;
- shared V3 primitives reused;
- persistent footer model preserved;
- compact header/full viewport;
- filters/IDs/rows comply with v4.2;
- live data is truthful;
- fake prototype data absent;
- AR/RTL + EN/LTR verified;
- representative narrow/wide runtime evidence/tests updated.

## 15. Customer production composition recipes

When creating a Customer page, compose from the current V3 system instead of raw Material widgets.

### Shell/navigation
- `FoodexTheme` / current Customer theme
- `CustomerPersistentFooterShell`
- `CustomerPersistentFooterDock`
- `CustomerPersistentFooter`

### Header/search/content
- `CustomerCurvedHeaderSurface`
- `CustomerSearchPill`
- `CustomerBadge`
- `CustomerOutlineIconButton`

### Catalog/commerce
- `CustomerCategoryTile`
- `CustomerProductImage`
- `CustomerProductCard`

### Loading/state
- `CustomerSkeletonBox`
- `CustomerCategorySkeleton`
- `CustomerProductCardSkeleton`
- `CustomerStateView`

Do not create a second persistent footer, independent customer color palette, or page-local loading/error component.

## 16. Customer archetype rule

- home/dashboard -> compact header + real account/commerce context + persistent footer;
- catalog/list -> search/filter + compact product/category primitives;
- order/invoice list -> compact scannable rows/cards, no-wrap reference, one overflow action when multiple actions exist;
- order/invoice detail -> exact record identity/status/timeline/action, no fabricated tracking;
- cart/checkout -> preserve current real commerce/session/payment journey;
- profile/account -> current persistent navigation and real settings/auth semantics.

Use `SCREEN_MANIFEST.json` B2B/B2C Customer entries as visual/reference coverage, but use current Flutter implementation for behavior.

