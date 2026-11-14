# V2 #3526 — Numeric reference input-type guard (P082)

- Parent register: #3477 (numeric overflow safety, already merged), #2783 (action permissions), #2503 (final V2 acceptance).
- Target: `Foodex_V2` only; never `main`, releases, APK publication, cPanel or production.

## Verified source defect

Before this change, `DisplayNumber::referenceId(true)` evaluated as integer ID `1` because PHP stringifies `true` as `"1"`; `DisplayNumber::reference(true)` displayed `#1`. Arrays triggered a PHP array-to-string conversion warning. A stringable object could also supply an apparent numeric reference. This is a typed-input integrity flaw in a shared helper, **not proof of missing HTTP authentication, cross-store access or production exploitation**.

## Implementation

- `referenceId` now accepts native `int` and `string` only, and rejects non-identifier types before conversion; `reference` handles native positive integers and textual digits, returning em dash otherwise.
- Preserve optional textual `#` prefix, leading zeros, existing whitespace handling, `PHP_INT_MAX` saturation rejection, invalid/zero behavior and native positive integer IDs.
- PHPUnit `Tests\\Unit\\Support\\DisplayNumberTest::test_reference_values_never_coerce_non_identifier_types` verifies booleans, floating values, arrays, objects, stringable objects and null fail closed, while native int and legacy textual forms remain accepted.
- No schema, route, role, permission, navigation, authorization scope or financial business rule changes. Existing tenant/role/store security remains authoritative.

## Impact and acceptance matrix

| Surface | Classification | Evidence |
|---|---|---|
| Laravel shared helper / Dashboard numeric display/search | Affected | Scalar type check added, existing positive numeric references preserved; PHPUnit positive/negative cases |
| Backend public B2B Customer invoice/search API | No contract change | Existing string search input and response JSON unchanged; existing API actor/store checks remain in place |
| Customer Flutter | Not affected | No Flutter files, REST route or payload shape touched |
| Driver Flutter | Not affected | No Driver route, payload, role or dispatch files touched |
| Van Flutter | Not affected | No Van route, payload, role or dispatch files touched |
| AR/EN and RTL/LTR | Not affected | No UI copy, translation key, template or layout change |

## Required proof before issue closure

- PHP syntax and exact test `backend/tests/Unit/Support/DisplayNumberTest.php`.
- Current `Foodex_V2` regression and applicable security/policy checks.
- Final-head Repository Policy, FOODEX V2 Isolation and FOODEX Required CI Gate must be terminal SUCCESS on the source PR's **last commit**, including all required child workflows. Record tested SHA, CI URLs and merge SHA in issue comment, then close atomic leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`.
- Manual/staging acceptance stays with #2503; **no claim of production deployment**.
