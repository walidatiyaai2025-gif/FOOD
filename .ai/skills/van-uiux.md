# Skill: FOODEX Van App UI/UX

Use for Flutter Van application screens.

## 1. Authoritative current sources

Read before editing:
- `apps/van_app/lib/core/theme/foodex_van_theme.dart`
- `apps/van_app/lib/features/foundation/van_foundation_screen.dart`
- `apps/van_app/lib/features/foundation/van_screen_inventory.dart`
- `apps/van_app/lib/shared/van_action_button.dart`
- current feature implementation
- `docs/design-reference/VAN_UIUX_APPROVED_TARGET.md`
- `docs/design-reference/VAN_DASHBOARD_PARITY_CONTRACT.md`
- `apps/van_app/docs/UIUX_V42_COMPLIANCE_EVIDENCE.md`

The approved Van mockup is the literal visual target, but fake prototype data is prohibited in production.

## 2. Van brand snapshot

Current Van tokens:
- Green `#158A3A`
- Green Dark `#165D2D`
- Green Soft `#EAF7EF`
- Surface `#FFFFFF`
- Background `#F6F8F6`
- Ink `#172033`
- Muted `#667085`
- Border `#E3E8E4`
- Warning `#EE731C`
- Danger `#EF5350`
- minimum touch target: 48 px
- card radius: 16 px

Use `FoodexVanTheme`, `FoodexVanTokens`, `VanActionButton` and `VanIconAction` where applicable.

## 3. Frozen 19-screen production inventory

Do not invent a replacement information architecture.

The production Van app owns:
1. Login
2. Home Dashboard
3. Routes
4. Route Map
5. Route Detail
6. Customers
7. Visit Workspace
8. Customer 360
9. Product Catalog
10. Order Builder
11. Order Review
12. Orders
13. Offers
14. Wallet
15. Collection
16. Receipt
17. Remittance
18. Notifications
19. Profile & Settings

A new requirement should extend/fit the real inventory unless the product contract explicitly changes it.

## 4. Navigation model

Current foundation shell owns navigation.

Primary bottom-navigation set:
- Dashboard
- Routes
- Customers
- Orders
- Wallet

Other production surfaces are reachable through the shell/drawer/workflows.

Arabic uses direction-appropriate drawer placement. Do not create a second unrelated navigation shell.

## 5. Visual contract

Van screens:
- green FOODEX identity;
- light neutral background;
- white bordered cards;
- compact 16-ish radii;
- compact mobile-first spacing;
- data-first hierarchy;
- no oversized nested cards;
- clear one primary action at a time;
- compact overflow actions instead of competing row buttons;
- connected visual flow across route -> customer -> selling -> collection -> wallet;
- AR/RTL and EN/LTR keep the same hierarchy.

## 6. Compact v4.2 mobile rules

- compact title/header;
- use full available phone width/height;
- keep identifiers on one line;
- Start/End/action filters stay one line when applicable;
- multiple row actions collapse to one green ellipsis;
- list/card density allows scanning multiple records;
- avoid giant padding/empty zones.

Current evidence is verified around standard 430x932 and compact 360x800 targets; new work must not regress either class of width.

## 7. Authoritative runtime data

Van production screens bind to real FOODEX services:
- Van auth/session;
- assignments/routes/visits;
- Customer/Store;
- Product/Selling Unit/pricing/offers;
- orders/order state;
- wallet/collection/receipt/remittance;
- permissions;
- push/session lifecycle.

Never copy fake prototype values into runtime.

## 8. Dynamic truthfulness

Dynamic Dashboard, Routes, Map, Route Detail, Customers, Customer 360, Visit, Orders, Wallet, Collection, Receipt, Remittance and Notifications must refresh through current server/resume behavior.

When retained data survives a failed refresh:
- label it last-confirmed/stale/offline;
- never make it look live.

## 9. Route map truthfulness

Only plot coordinates that actually exist on authoritative records.

If a stop has no coordinates:
- omit the fake pin;
- visibly explain missing location where relevant.

Do not fabricate geolocation.

## 10. Business-action constraints

UI cannot invent local business authority.

Examples:
- order completion depends on authoritative same-customer/store order;
- no-order completion depends on a configured active reason;
- commercial price/unit/offer validation comes from authoritative rules;
- finance screens do not invent balances locally.

## 11. Dashboard parity

Van capability does not mean cloning mobile layout into Dashboard.

When adding a Van capability, check the parity contract:
- Dashboard exposes the appropriate desktop/admin control plane over the same authoritative business data/rules;
- Van app remains a consumer, not a separate source of truth.

## 12. Authentication identity

Login visibly identifies:
- `Van App`
- `تطبيق الفان`

Remember Me + biometric secure-session behavior must remain intact.

## 13. Van screen Definition of Done

- fits canonical 19-screen navigation model or explicitly updates it;
- Van theme/tokens/shared actions reused;
- compact data-first layout;
- no fake prototype data;
- authoritative server semantics;
- no-wrap identifiers;
- compact ellipsis actions;
- explicit stale/offline behavior;
- AR/RTL + EN/LTR;
- standard and compact phone widths verified;
- screenshot/widget evidence updated when required;
- Dashboard parity evaluated for business capability changes.

## 14. Van production composition lock

`VanFoundationScreen` is the navigation/shell authority.

A new Van page must plug into the current foundation model:
- primary navigation remains Dashboard / Routes / Customers / Orders / Wallet;
- secondary production surfaces remain reachable through the existing drawer/workflows;
- Arabic drawer direction follows the existing end-drawer behavior;
- `FoodexVanTheme` / `FoodexVanTokens` own visual tokens;
- `VanActionButton` and `VanIconAction` own standard actions.

Do not add a second Scaffold/navigation architecture merely to implement one screen.

For page-level records, follow the v4.2 compact field-operations pattern:
- compact header;
- real record identity and status;
- dense scannable cards/list;
- one overflow action pattern when several actions exist;
- explicit loading/empty/error/stale/offline;
- authoritative mutation then refresh/reconcile.

