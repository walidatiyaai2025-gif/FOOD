# V2 #3485 — fixed quantity precision must reject negatives

Parent acceptance #2503. Target branch **Foodex_V2 only**. Source: `backend/app/Support/DisplayNumber.php::quantity()`, existing `backend/tests/Unit/Support/DisplayNumberTest.php`.

## Reproduction / change

`quantity(123.45, -1)` historically threw `InvalidArgumentException` through private `compact()`, while `quantity(123.45, -1, preserveFixedPrecision: true)` bypassed that guard and called PHP `number_format()`. PHP 8.4 formats `number_format(123.45, -1)` as `120`, silently rounding to tens instead of rejecting invalid precision. This violates the shared helper's documented contract.

The public `quantity()` entry now rejects `fractionDigits < 0` before either output branch using the existing exception type/message. The private `compact()` contract remains a defensive guard. No changes to allowed nonnegative precision, fixed trailing zeros, compact formatting, currencies or numeric reference parser (#3477 already merged).

## Actual scope, permission and app impact

| Surface | Classification | Rationale |
| --- | --- | --- |
| Backend | Affected developer-facing helper argument validation; no DB, HTTP route, schema or transaction changes | Regression is the existing pure PHPUnit `DisplayNumberTest` |
| Dashboard | Existing quantity formatting contract only for invalid internal caller configuration; valid grids, AR/EN and RTL/LTR unchanged | No new UI, navigation, permissions, master data, store/tenant/finance action |
| Customer | Not affected | No public API contract or Customer Flutter change; no valid amount/quantity payload change |
| Driver | Not affected | No Driver Flutter/dispatch/collection API or numeric formatter change |
| Van | Not affected | No Van Flutter/offline stock/route/dispatch API or numeric formatter change |

B2B remains VAN ONLY; B2C remains DRIVER ONLY. No app auth, invoices, order prices, finance ledger or live uploads are modified. No production data used.

## Tests / exact-head delivery

- Regression: `backend/tests/Unit/Support/DisplayNumberTest.php` now tests negative precision with `preserveFixedPrecision: true` and the exact preexisting exception message, alongside existing compact negative and valid fixed precision tests.
- Required before merge: PHP syntax, Pint, PHPStan/security, focused test + full applicable Laravel tests, AR/EN localization, MySQL/Redis acceptance where triggered.
- All required **Repository Policy**, **FOODEX V2 Isolation Gate** and **FOODEX Required CI Gate** child jobs must be terminal GREEN on latest exact PR HEAD. Merge through one issue-scoped PR into Foodex_V2 only, close leaf after integration SHA and CI proof. Manual/staging and entire V2 final audit remain #2503.
- This file records source QA, not a CI execution result; CI links are added to the Issue/PR after genuine verification.

No main, tags, releases, cPanel or production.
