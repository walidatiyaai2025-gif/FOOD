# FOODEX Localization Contract

This is a mandatory project-wide Definition-of-Done contract for every Dashboard page, Customer app screen, Driver app screen, Van app screen, feature, function, popup, modal, notification surface and user-facing workflow.

## Core invariant

The selected application language controls all user-facing system wording.

- When locale is Arabic, system labels, actions, statuses, enum labels, validation messages, headings, empty states and localized business labels must resolve to Arabic.
- When locale is English, the corresponding system wording must resolve to English.
- A page must never appear Arabic while newly introduced system labels/data statuses remain English merely because the backend value or developer literal is English.

## Mandatory implementation rules

1. Do not add raw user-facing strings directly inside Flutter UI widgets or Blade markup when the text is translatable.
2. Add every new translation key to both Arabic and English catalogs in the same change.
3. Customer and Driver Dart translation maps must maintain exact AR/EN key parity.
4. Backend `lang/ar` and `lang/en` files must maintain matching files and matching flattened keys.
5. Status, state, channel, role, type, payment-method and selling-unit labels must not be rendered as raw internal/backend values. They must be mapped through a localization key or a locale-aware label returned by an authoritative localized-data contract.
6. New business/master/catalog data that is intended to be bilingual must expose a deterministic localized representation, for example:
   - `name_ar` + `name_en`
   - `label_ar` + `label_en`
   - `title_ar` + `title_en`
   - or a server-provided locale-resolved display field whose behavior is covered by tests.
7. Internal IDs, codes and machine keys are not translated, but they must not replace human-readable localized labels in business UI.
8. User-entered proper names or externally supplied content are not machine-translated unless the product explicitly owns translated variants. The surrounding system labels still follow the selected locale.
9. Technical brand/acronym tokens such as FOODEX, API, SKU, QR, B2B and B2C may remain as approved technical tokens where appropriate.
10. Arabic and English layout parity includes RTL/LTR direction and equivalent information density; localization must not hide data or change business meaning.

## CI enforcement

The repository workflow `.github/workflows/localization-quality-gate.yml` is mandatory and is also consumed by `FOODEX Required CI Gate`.

The gate checks:

- Customer/Driver Dart AR/EN translation-key parity.
- Backend Arabic/English language file/key parity.
- New raw user-facing Flutter literals.
- New raw user-facing Blade text/attributes.
- New direct rendering of status/state/channel/role/type/payment-method/unit values without localization.
- Direct rendering of language-specific fields such as `name_en`, `name_ar`, `title_en` or `title_ar` instead of selecting through the active-locale resolver.
- New Arabic catalog entries that are English-only, and English catalog entries that contain Arabic text, excluding approved technical tokens.
- Customer, Driver and Van localization runtime tests for Arabic/English rendering.

The localization gate is blocking. A failed localization check is a real CI failure, not advisory output. It is consumed by Required CI and by the release distribution workflow; a release must not be published while localization quality is red.

For system-owned wording and localized business labels, silently falling back to the opposite language is not an acceptable success state. If Arabic is selected and an Arabic system translation is missing, the missing translation must be fixed rather than quietly showing the English system label (and vice versa).

## New page / feature requirement

Any new page, feature or function must be designed with localization in the same implementation, not as a later cleanup task.

A PR is incomplete when:

- Arabic mode visibly contains untranslated English system wording introduced by the change.
- English mode visibly contains untranslated Arabic system wording introduced by the change.
- only one locale received the new translation key.
- a backend enum/status/code is shown directly to the user.
- localized master data exists but the page always chooses the English/default field regardless of locale.

## Local preflight

Workers must run:

```bash
python3 scripts/localization-quality-gate.py --base <merge-base> --head WORKTREE
```

The standard worker preflight runs this automatically.
