# Customer responsive audit — #1249

## Scope

FOODEX Customer App responsive hardening applies across Retail/B2C and Wholesale/B2B routes. The implementation fixes shared layout primitives first so existing and future screens inherit safer behavior instead of relying on one-off screen patches.

## Locked validation matrix

| Dimension | Coverage |
| --- | --- |
| Widths | 320, 360, 390, 430 logical px |
| Text scale | 1.0, 1.3 |
| Direction | Arabic RTL, English LTR |
| System UI | SafeArea/status/navigation insets |
| Keyboard | Footer hidden while IME is materially open |
| Content | loading, empty, error, success |

## Shared hardening

- B2B More header no longer uses fixed Stack positions or 104px title padding. Actions, title, B2B badge, and back action now share constrained responsive space.
- B2B More cards grow with system text scale instead of keeping a fixed 112px height.
- Customer account headers allow two-line title/subtitle layouts on narrow or enlarged-text devices and move optional trailing controls below the title when required.
- Persistent Customer footer follows inherited LTR/RTL direction, can grow beyond its minimum height, and allows two-line labels rather than shrinking text.
- Persistent footer dock uses actual system bottom inset instead of a fixed 42px compensation.
- App version/footer overlay now reserves bottom layout space before painting so it cannot cover page content.
- B2B invoice title is flexible and ellipsizes within the remaining AppBar width.
- Retail catalog product grids increase card extent with system text scale, matching the responsive behavior already used by the V3 home grid.

## Route-family audit checklist

- [x] Authentication/onboarding: shared Material/SafeArea and text-scaling path retained.
- [x] Home/marketplace: V3 grid already scales product card extent.
- [x] Categories/search/products: catalog grid and shared category/product primitives reviewed.
- [x] Product detail: vertically scrollable detail surface retained.
- [x] Cart/checkout: existing scroll + keyboard-safe flows retained.
- [x] Orders/order detail/tracking: existing responsive widget coverage retained.
- [x] Notifications/addresses/favorites/profile: shared account header hardening applies.
- [x] B2B dashboard/invoices/reports: AppBar and footer behavior reviewed.
- [x] B2B More/business account: original narrow-header collision removed.
- [x] Dialogs/bottom sheets: SafeArea usage retained; B2B sheets remain scrollable.
- [x] Persistent navigation: footer reserves layout space and honors system insets.

## Automated regression evidence

- B2B More regression: 320px viewport + 1.3 text scale with title/back controls visible and no Flutter exception.
- Account regression: 320px viewport + 1.3 text scale in RTL with all account shortcuts reachable.
- Footer regression: 320px + 1.3 text scale in both English LTR and Arabic RTL.
- Visual acceptance contract explicitly requires 320/360/390/430 widths, AR/EN, 1.0/1.3 text scale, all runtime states, and reduced-motion coverage.
- Existing multistore, browse, commerce, orders, account and B2B suites remain part of Customer validation CI.

## Definition-of-done guard

No solution disables global text scaling. No fixed-width device assumption is introduced. Shared primitives prefer constraints, wrapping, scrolling, SafeArea and dynamic insets so newly added Customer screens inherit the same responsive behavior.
