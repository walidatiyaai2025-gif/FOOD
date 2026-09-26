# PH-06 Mobile Screenshot Readability QA

Issue: #183

## Before

The committed Customer and Driver runtime PNGs were already 430×932 portrait images, but Flutter widget-test evidence used the Ahem test font. Arabic/English text and Material Icons therefore rendered as square glyph blocks and were not suitable for human visual review.

## Repair

- Keep the real screenshot viewport at 430×932 with devicePixelRatio 1.
- Load an Arabic-capable system font explicitly for screenshot evidence.
- Load Flutter's Material Icons font explicitly for screenshot evidence.
- Apply the evidence font through a test-only theme override; normal production theme selection is unchanged.
- Capture Customer and Driver, B2B and B2C, Arabic and English runtime screens.
- Verify every mobile PNG is exactly 430×932 and has portrait ratio >= 1.6.
- Refresh the canonical screenshot manifest and audit from the regenerated PNGs.

## Visual verification

Representative regenerated Arabic B2C Customer evidence was inspected after capture:
- Arabic labels render as readable Arabic text.
- Latin product metadata remains readable.
- Material navigation/chevron/cart/profile/home icons render as icons rather than glyph boxes.
- The full app fills the portrait canvas without square crop, resize or stretch.

The capture workflow completed all Customer, Driver, geometry, manifest and commit steps successfully before this QA record was added.

## Scope integrity

No backend API, database schema, permission rule, order/customer/product business behavior, or production runtime endpoint was changed by this repair.
