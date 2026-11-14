# V2 #3477 — bounded numeric reference parsing

**Source contract:** parent #2503; P082 action/route security trace and pre-existing Admin Orders/Customer 360/Global Search/B2B invoice read surfaces. **Integration target:** `Foodex_V2` exclusively.

## Reproduction and corrective boundary

- Previous `DisplayNumber::referenceId` and `reference` used a direct `(int)` cast on arbitrary positive digit strings; on 64-bit PHP, `9223372036854775808` becomes `9223372036854775807`, potentially matching/printing an unrelated real identifier. This is a display/search correctness hazard, not evidence of an authentication bypass.
- The shared formatter now validates positive decimal ASCII digits, strips optional *search-only* `#` and leading zeros, compares normalized length and lexicographic value with `PHP_INT_MAX` **before** the cast, then converts only an in-range value.
- Legitimate `42`, `#42`, `00042`, `#00042`, whitespace around a search token, and exact `PHP_INT_MAX` remain accepted. Zero, signed/malformed/multiple-prefix values and numbers above that maximum fail closed (`null` for lookup; the pre-existing `—` unavailable display marker for an invalid reference).
- There is no change to RBAC, query shape, store/channel/tenant filters, search wildcard scope, finance approvals, balances, migrations, or user data. Existing authorized result links and UI layout are unchanged.

## Runtime and mobile impact classification

| Surface | Classification | Evidence and tests |
| --- | --- | --- |
| Backend | Affected helper, read-only strict input semantics | `DisplayNumber::referenceId` drives Admin order/finance search; unit tests cover maximum, overflow, zero, prefix, padding and malformed inputs |
| Dashboard | Existing search/display behavior affected **only** for invalid overflowing numeric references | Order Operations, Global Search, Customer 360 and live tracking continue calling same helper with existing route authorization and tenant scope; no new UI/route/AR-EN text |
| Customer | B2B finance invoice search API consumes the same shared parser, so an invalid overflow search stops matching a saturated ID. Existing Customer invoice/order parity tracked by **#2733**; no successful valid input or JSON schema changes, no Flutter source modification | Unit boundary contract; existing Laravel CI API and scope tests required |
| Driver | **Not affected**: dashboard driver live tracking search is an admin UI endpoint, not Driver app/API dispatch contract | No Driver Flutter or driver status/assignment API source changed |
| Van | **Not affected**: no B2B Van app/API inventory/route/order or offline parsing source changed | No Van Flutter or fulfillment source changed |

B2B remains **VAN ONLY**; B2C remains **DRIVER ONLY**. No credentials or production records used.

## Acceptance and gates

- Focused test: `cd backend && php artisan test tests/Unit/Support/DisplayNumberTest.php` (or `vendor/bin/phpunit tests/Unit/Support/DisplayNumberTest.php`).
- `php -l` and repository Pint/PHPStan must pass; all existing Laravel/API/tenant/security tests and AR/EN quality remain required.
- On the **exact final PR head** require terminal SUCCESS for Repository Policy, FOODEX V2 Isolation and FOODEX Required CI (including applicable Laravel, PHP syntax/Pint/PHPStan/security, localization and MySQL/Redis).
- Merge only the issue-specific PR into `Foodex_V2`, not `main`; close this atomic source leaf only after full exact-head evidence. Manual/staging and consolidated V2 audit remain tracked under #2503.

This QA record is a source/impact matrix, **not** proof of CI or live environment acceptance until run links and commit SHAs are attached to the Issue/PR.
