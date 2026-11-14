# FOODEX V2 #3575 — Translation Center literal substring search

## Source and ownership
- Master: #2503 source-to-Issue program, P384 localization/translation governance and existing Translation Center source feature #91.
- Canonical entry: Admin Settings → Translations (`GET /admin/settings/translations`), existing `admin.translations.index` route and `translations.manage` Gate. **Reuse** the existing editor and navigation; no extra screen, Sidebar item, permission, or action.
- Scoped original PR into `Foodex_V2` only, never main, tags, release, cPanel, or production.
- Owned files: existing `TranslationController::index`, new `V2TranslationLiteralSearchTest`, this QA note.

## Source discrepancy
Existing `q` search passed user text through bound SQL `LIKE '%...%'`, interpreting percent and underscore as wildcard patterns and returning unrelated authorized translation keys/values. Bound parameters already prevented SQL injection. This is a search fidelity defect, **not** a demonstrated data-leak or write-authority bypass.

## Implementation
- Search predicate now matches a literal substring: escape `!`, `%`, `_` using explicit `!` SQL escape before adding intended surrounding percent wildcard.
- Three **fixed trusted** columns (`key`, `ar`, `en`) are searched via `LIKE ? ESCAPE '!'` with bound parameters, portable to SQLite and MySQL/MariaDB without relying on backslash SQL mode.
- Unchanged: `translations.manage` permission gate; `surface` exact filter; `TranslationCatalog::syncDefaults`; 25-row pagination and sort; editor/resets/audit and existing AR/EN/RTL-LTR responsive UI. No schema or translation value changes.
- Laravel `RefreshDatabase` authenticated HTTP regression covers key literal `%_`, escape `!_`, Arabic value containing `%_`, English value containing `!_`, false wildcard matches, ordinary substring, filtered surface and unauthorized B2B Admin 403. Existing accessible translation editor and full translation tests remain applicable.

## Surface and application matrix
| Surface | Source impact | Contract |
|---|---|---|
| Laravel admin web controller | Affected, read-only filter semantics | Existing route/Gate, bound SQL, exact HTML editor |
| Dashboard Translation Center | Affected via existing search only; no UI change | AR/EN, RTL/LTR, accessibility and responsive layout unchanged |
| Customer Flutter | No app, API, translation payload change | Existing translation API unchanged |
| Driver Flutter | No app, API, translation payload change | Existing translation API unchanged |
| Van Flutter | No app, API, translation payload change | Existing translation API unchanged |

B2B Van-only dispatch and B2C Driver-only dispatch are untouched.

## Acceptance
Exact final source-head Repository Policy and V2 Isolation success, full applicable Laravel tests, PHP syntax/Pint/static analysis/security, AR/EN localization and real MySQL/Redis acceptance, plus terminal `required-ci-gate` success. Merge only the issue-scoped PR into Foodex_V2 with expected head SHA, then document tested SHA, integration SHA and CI run links. Only after this may this leaf close `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`; manual/staging and all-source V2 acceptance remain on open master #2503.
