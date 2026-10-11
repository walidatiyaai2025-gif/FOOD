# Skill: Flutter Localization Code

Use when Flutter code adds or changes user-visible strings, labels, statuses, validation messages, buttons, snackbars, dialogs or semantic labels.

## Rules
- user-visible business/system text must come from the current FOODEX localization mechanism;
- AR and EN keys/values change together;
- do not display raw backend enum/status/channel/role/type values;
- Arabic mode must not leak English system wording and English mode must not leak Arabic system wording;
- preserve meaning, action priority and information density in both RTL and LTR;
- avoid concatenating translated fragments when grammar/order can differ by locale;
- numbers/currency/quantity/date formatting must use the project/domain formatter instead of hand-built locale text.

## Layout
Longer translations must not break the compact mobile contract. Use flexible layout, truncation/wrapping rules appropriate to the field, and preserve no-wrap identifiers where the product contract requires them.

## Proof
Update localization parity tests/gates and add a focused widget/unit assertion when the defect was caused by a missing key, raw status or direction-sensitive layout.
