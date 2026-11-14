# V2 #3591 — Retail Store Provisioning literal search QA

Parent #2503 / #2818 P082. **Integration base: `Foodex_V2` only.** No `main`, production, tags, release, APK publishing or cPanel activity.

## Authority and bounded source correction

- Canonical route: `GET /admin/retail-stores`, Super Admin only via `RetailStoreProvisioningController::superAdmin`. Existing Retail Administration navigation, 25-row paginated list, B2C `store_types.code` restriction, ordering and query-string filters remain authoritative.
- Defect: fixed-column `stores.name` and `stores.code` `LIKE` clauses previously treated a user-entered `%` / `_` as wildcard operators. Search was parameter-bound; this was an accuracy issue, **not an identified SQL injection or tenancy bypass**.
- Correction: escape `!`, `%`, `_` using `!` as an explicit `LIKE` escape; wrap the escaped string with intentional `%` delimiters. Fixed SQL identifiers and bound parameters are retained, compatible with SQLite and MySQL/MariaDB.
- Unchanged: create/update/suspend/close/inspect mutations, roles, store ownership, B2B wholesale link, audit and export, tenant and role authority, error states, AR/EN and RTL/LTR UI, responsive desktop/tablet/mobile composition. No new route, Sidebar item, label or master data source.

## Real HTTP regression coverage

`backend/tests/Feature/RetailStoreProvisioningTest.php` adds `RefreshDatabase` requests with real core reference seeds:

- Super Admin searches literal `%`, `_`, `!` in names and underscores in codes, with negative lookalikes that SQL wildcard matching would incorrectly include.
- Ordinary English and Arabic store-name searches continue to work; a matching Wholesale/B2B store is absent from the Retail/B2C list.
- An authenticated B2B Admin receives 403 on the direct Retail Store Provisioning URL.
- Search GETs preserve store/audit counts and pagination remains 25 rows with the literal `q` retained on page two.

## Frontend / mobile impact matrix

| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend | Affected: one existing fixed-column index query | Explicit bound literal `LIKE ... ESCAPE '!'` |
| Dashboard | Affected: filter precision on existing store list | Existing route, auth, pagination, AR/EN and responsive Blade reused without markup change |
| Customer App | Not affected | No Customer API/JSON, schema, route, Flutter or authentication change |
| Driver App | Not affected | No Driver API/dispatch/status/Flutter change; B2C=DRIVER ONLY unaffected |
| Van App | Not affected | No Van API/visits/stock/Flutter change; B2B=VAN ONLY unaffected |

## Completion gates

- [ ] PHP syntax, Pint, static analysis, security/OpenAPI and focused/full Laravel HTTP tests on final source
- [ ] Localization AR/EN and MySQL/Redis acceptance where CI requires
- [ ] Exact-final-head `Repository Policy`, `FOODEX V2 Isolation Gate`, `FOODEX Required CI Gate` terminal SUCCESS
- [ ] Source review, original branch and target revalidated, SHA-guard merge exclusively to `Foodex_V2`
- [ ] Close only this atomic implementation leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with tested SHA, run URLs, merge SHA. Master #2503 remains open for final staging/live UAT.
