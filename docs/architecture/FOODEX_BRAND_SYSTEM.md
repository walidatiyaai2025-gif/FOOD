# FOODEX Brand System

Status: authoritative for PH-05 and all later UI work.

## Source

The palette and visual direction are sampled from the user-approved FOODEX dashboard reference supplied on 2026-09-25. Workers must not invent a second green/orange palette.

## Canonical tokens

| Token | Value | Use |
|---|---|---|
| Primary green | `#158A3A` | active navigation, primary actions, positive brand emphasis |
| Dark green | `#165D2D` | wordmark, high-contrast brand text |
| Bright green | `#27B658` | chart/positive accents |
| Green soft | `#EAF7EF` | selected/positive surfaces |
| Primary orange | `#EE731C` | secondary action and FOODEX leaf/accent |
| Bright orange | `#FC8F33` | charts and soft emphasis |
| Orange soft | `#FFF1E6` | warm card surfaces |
| Info blue | `#4B8CF5` | in-transit/info states and charts |
| Error red | `#EF5350` | cancelled/destructive states |
| Ink | `#172033` | primary text |
| Muted | `#667085` | secondary text |
| Surface | `#FFFFFF` | cards/topbar/sidebar |
| Background | `#F7F9FC` | page canvas |
| Border | `#E6EAF0` | card/control borders |

## Canonical typography

FOODEX uses **Tajawal** as the single UI typeface across Arabic, English and numeric presentation.
- Laravel/Admin loads Tajawal from Google Fonts at weights 400, 500 and 700 through `_brand.blade.php`.
- `--foodex-font-ar`, `--foodex-font-en` and `--foodex-font-ui` remain compatibility aliases, but all resolve to the same `--foodex-font-family`.
- Customer Flutter and Driver Flutter use `google_fonts` and `GoogleFonts.tajawalTextTheme` as the default Material typography.
- Legacy Inter, Tahoma, Arial, system UI stacks and per-page UI font overrides are prohibited.
- Monospace remains allowed only for technical identifiers, translation keys, code and machine-readable values.

## Implementation contract

Laravel/Admin consumes `backend/resources/views/admin/_brand.blade.php`.
Customer Flutter consumes `apps/customer_app/lib/core/theme/foodex_theme.dart`.
Driver Flutter consumes `apps/driver_app/lib/core/theme/foodex_theme.dart`.

The numeric color values must stay identical across these three sources. Any palette change requires one Issue updating all three surfaces and visual-regression evidence.

## Branding rules

- FOODEX green is the primary interactive brand color.
- FOODEX orange is an accent, not the dominant page background.
- Primary application surfaces are white/light neutral as in the approved dashboard.
- Arabic is RTL-first. English mirrors layout direction without changing information hierarchy.
- Status colors are semantic: green delivered/success, blue in-transit/info, orange processing/warning, red cancelled/error.
- Use neutral ink for dense text; do not use dark navy as the dominant brand surface.
- Avoid gradients except when explicitly approved by the design contract.
- Cards use 14–18px radii, subtle borders and soft shadows; controls use 10–12px radii.
- Mobile and B2C may adapt component density but must preserve the same token semantics.
