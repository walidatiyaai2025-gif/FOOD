# Skill: FOODEX Driver App UI/UX

Use for Flutter Driver screens and flows.

## 1. Current implementation sources

Read before editing:
- `apps/driver_app/lib/core/theme/foodex_theme.dart`
- `apps/driver_app/lib/features/delivery/active/driver_active_journey.dart`
- `apps/driver_app/lib/navigation.dart`
- current feature folder under `apps/driver_app/lib/features/`
- `docs/execution/UIUX_V42_RECOVERY_1039_DRIVER_EVIDENCE.md`
- current Driver route inventory when navigation changes

## 2. Driver brand snapshot

Current Driver theme uses:
- Green `#158A3A`
- Green Dark `#165D2D`
- Green Bright `#27B658`
- Green Soft `#EAF7EF`
- Orange `#EE731C`
- Orange Bright `#FC8F33`
- Orange Soft `#FFF1E6`
- Blue `#4B8CF5`
- Red `#EF5350`
- Ink `#172033`
- Muted `#667085`
- Surface `#FFFFFF`
- Background `#F6F8F6`
- Border `#E3E8E4`

Typography uses Alexandria where configured.

Current theme geometry includes:
- AppBar height 64;
- title about 19/w800;
- NavigationBar height 74;
- FilledButton min height 52;
- text-button minimum 44x44;
- common radius around 14–16;
- pill chips;
- input radius 16.

Use theme primitives; do not recreate a different Driver palette.

## 3. Driver is operational/data-first

A Driver screen prioritizes:
1. exact assignment/order identity;
2. current authoritative state;
3. store/customer/address context;
4. next allowed action;
5. navigation/proof/settlement context when relevant;
6. stale/offline truthfulness.

Avoid decorative hero headers and oversized cards.

## 4. Current v4.2 active-journey pattern

`driver_active_journey.dart` is an important current reference.

Reuse its interaction principles:
- compact assignment cards;
- full-width shell;
- one-line filter surface;
- one-line order/reference;
- concise store/customer/address text with ellipsis when necessary;
- one **green ellipsis PopupMenu** for row actions;
- explicit detail surface;
- bottom-sheet/dialog for focused transitions;
- localized status labels;
- explicit stale-data banner with last-confirmed update.

Do not regress lifecycle actions back into many wide buttons on every list row.

## 5. Filter contract

At narrow widths, filters still stay one logical line.

Current reference pattern supports:
- date range;
- Today;
- All;
- clear date when custom.

Use horizontal scroll/compact controls instead of stacking the common filter bar vertically.

## 6. Assignment / order identifiers

Order/reference identifiers:
- remain one line;
- stay visually unambiguous;
- use ellipsis only with a direct way to see full value where needed;
- must not split into multiple lines.

## 7. Dynamic refresh

Current v4.2 expectations:
- foreground polling for active live surfaces where implemented;
- refresh on app resume;
- stop unnecessary polling in background/disposed state;
- retain last-confirmed data on network failure only with visible stale/offline treatment;
- show last-confirmed/update context when useful.

Never silently keep old assignments looking live.

## 8. Driver state surfaces

Explicitly handle:
- loading;
- ready;
- empty;
- error;
- offline;
- stale-with-retained-data.

A generic spinner forever or silent empty list is not enough.

## 9. Push / deep links

Foreground new-order/assignment UI must:
- show authoritative Order/Assignment identity;
- provide direct View/Open;
- deduplicate duplicate events;
- if record is already open, refresh instead of stacking duplicate alerts;
- route to exact authorized authoritative assignment/order;
- never grant access solely because a push payload contains an ID.

## 10. Authentication identity

Login visibly says:
- `Driver App`
- `تطبيق السائق`

Remember Me / biometric:
- use secure session persistence;
- no plaintext password;
- logout/revocation invalidates saved access appropriately.

## 11. AR/EN and responsive verification

Verify at narrow and normal phone widths:
- AR/RTL;
- EN/LTR;
- compact header;
- one-line filters;
- no-wrap reference;
- ellipsis actions;
- stale/offline banner;
- lifecycle detail actions.

## 12. Driver screen Definition of Done

- Driver theme reused;
- operational hierarchy preserved;
- exact identity/state/action visible;
- compact rows/cards;
- one-line filters and references;
- one green ellipsis menu for row actions;
- authoritative lifecycle action;
- explicit error/offline/stale;
- live refresh behavior preserved;
- exact-record push/deep-link behavior safe;
- AR/EN + RTL/LTR verified;
- relevant widget/navigation tests updated.
