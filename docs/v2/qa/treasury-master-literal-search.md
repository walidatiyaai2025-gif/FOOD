# FOODEX V2 #3600 — Treasury Master literal search QA

**Source:** #2503 P082 / #2818; related canonical Treasury Sidebar #2621/#2622. **Only** `Foodex_V2`; no main, release, tags, production, cPanel or APK publishing.

## Source defect and bounded resolution
`TreasuryMasterController::filterQuery` previously concatenated user text into `LIKE '%q%'` for each fixed cashbox/bank-account search field. The query was parameterized by Eloquent but SQL wildcard characters in `q` expanded to unrelated results. This is **read-accuracy**, not an asserted SQL injection or finance scope bypass.

The filter now encodes `!` as `!!`, `%` as `!%`, and `_` as `!_`, then wraps with intentional substring `%`. Each static internal column gets `LIKE ? ESCAPE '!'` with a bound pattern. Explicit escape works on SQLite test and MySQL/MariaDB production dialects. The column list remains hard-coded in the two `index()` call sites, never from user input.

## Authority and UI
- **Domain / entity / role:** Finance & Treasury / Cashbox and Bank Account / scoped `finance.view` user with authorized store. `finance.manage` remains required for mutations and edit links.
- **Placement:** REUSE canonical `GET /admin/treasury/master-data` and existing Cashboxes/Bank Accounts tabs, Data Explorer filter, query-string pagination, Status and Store selection, no alternate destination or new UI.
- **Data security:** existing `OperationalTenantScope::allowedStoreIds`, `assertStore` and `whereIn(store_id)` are unchanged. Bank account reference encryption/masking and export/mutation/audit are untouched.
- **AR/EN / mobile web:** existing RTL/LTR responsive Blade, display copy, keyboard controls and empty states are unchanged; only read-query matching changes. Existing user-facing workflow continues in both locales.

## Real HTTP regression
New `backend/tests/Feature/V2TreasuryMasterLiteralSearchTest.php` uses `RefreshDatabase`, seeded roles, real Retail finance actor, and store + company hierarchy:
- Searches combined literal `%_!`, individual `%`, literal `!_`, real underscore in Cashbox and Bank Account codes, normal English and Arabic terms, unmatched literal sequence; excludes wildcard-lookalikes.
- Applies active/inactive filters and checks *both* independent collections returned from the existing page, including in the Bank Accounts tab.
- Denies cross-store `store_id` (404), roleless (403) and store-level explicit permission DENY (403). Foreign-store fixture is never returned.
- GET does not change Cashbox, Bank Account or audit record count; encrypted bank references never appear in HTML.
- Twenty-six cashboxes verify first-page 25, second-page one, retained literal query/status/store/per_page and independent bank paginator.

## Cross-app impact matrix
| Surface | Impact |
| --- | --- |
| Backend | Bound query filter in the existing Treasury Master Controller only |
| Dashboard | Result membership on the existing authorized list; AR/EN and responsive view reused |
| Customer App | No API/DTO/auth/Flutter change |
| Driver App | No B2C dispatch, wallet, collection API/Flutter change |
| Van App | No B2B trips/stock/settlement API/Flutter change |

B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged; no app-level change or new mobile issue is required.

## Acceptance gates
- PHP syntax/Pint/PHPStan/security/OpenAPI; focused and full Laravel feature tests; AR/EN; MySQL/Redis install/upgrade/recovery where required.
- Repository Policy, FOODEX V2 Isolation Gate and terminal FOODEX Required CI on exact final PR HEAD; independent review where required.
- One issue-owned branch and one PR to `Foodex_V2` only; SHA-guard merge after all checks. Close only atomic leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with PR, tested SHA, integration SHA and CI links.
- Parent #2503 stays OPEN for final human staging/mobile/browser UX and whole-system acceptance. No release readiness inferred.
