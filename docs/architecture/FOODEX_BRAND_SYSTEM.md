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
| Mobile splash background | `#003223` | Android/iOS launch and branded mobile splash |
| Mobile splash lime | `#92D853` | leaf/logo accent on mobile launch surfaces |

## Canonical typography

FOODEX mobile apps use **Alexandria** as the premium bilingual UI typeface for Arabic, English, and numeric presentation.
- Customer Flutter and Driver Flutter use `google_fonts` with `GoogleFonts.alexandriaTextTheme` as the default Material typography.
- Laravel/Admin continues to use Tajawal for the current Web visual baseline; a Web typography change must be handled as a coordinated visual-QA change.
- Legacy Inter, Tahoma, Arial, system UI stacks and per-page mobile UI font overrides are prohibited.
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
