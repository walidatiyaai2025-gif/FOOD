# V2 #3595 — Reports Center literal customer/product lookup search

- Source: unified P082 granular View authorization (#2818), V2 master #2503. No cross to `main`, tag, release or production.
- Owner boundary: issue #3595, branch `fix/3595-v2-reports-lookup-literal-search`, PR to `Foodex_V2` only.
- Route/entry: existing authorized Reports Center `/admin/reports` entity picker, `GET /admin/reports/lookups/products` and `GET /admin/reports/lookups/customers` handled by `ReportController::lookup`. Canonical route and authenticated `reports.view` control remain unchanged.
- Placement: **Reuse** the existing Reports Center picker, no Sidebar, menu, screen, JSON schema, UI label, CSS, JavaScript, mobile app or API v1 change.
- Issue: SQL LIKE binding alone does not make input `%`/`_` literal. Escape the input's `!`, `%`, `_` using `!!`, `!%`, `!_` before adding the intended substring wildcards. Fixed-column bound `LIKE ? ESCAPE '!'` only; no dynamic SQL identifiers.
- Customer scope: existing `OperationalTenantScope::allowedStoreIds`, B2B order-scoped customer membership, B2C store scope, required B2B/B2C channel, explicit denied-store 403 and security-first validation order remain intact. Product scope: catalog/store/channel/category and active metadata returned unchanged.
- Preserve ordering, 25-row continuation `next_page`, response envelope, validated parameters and absence of GET mutations/audit writes.
- Tests: append authenticated `RefreshDatabase` regressions in `ManagementReportingSuiteTest.php`: `%`/`_`/`!` and Arabic search; products and both customer channels; store-admin allowed/foreign store denied, role-less actor denied; 26 literal-match page continuation; GET audit invariance. CI is authoritative for execution; source checks do **not** prove a test has passed until Actions reports SUCCESS.

## Access and surface impact

| Surface | Impact |
| --- | --- |
| Laravel Backend | Search predicate only, no schema/contract change |
| Dashboard | Existing picker search now literal while keeping AR/EN/RTL/LTR/responsive UI, disabled state and empty result behavior |
| Customer Flutter | Not affected: only authenticated admin web route; no API v1 or identity change |
| Driver Flutter | Not affected: no dispatch/collection/notification/API change |
| Van Flutter | Not affected: no B2B assignment/routing/stock/API change |

## Acceptance evidence required before closure

- Exact source/test/doc diff and reviewed scope; all branch commits traceable to #3595.
- GitHub Repository Policy, FOODEX V2 Isolation Gate, Required CI Gate and all applicable Laravel, Pint/static/security, AR/EN, and MySQL/Redis checks **SUCCESS on the final PR HEAD**, and required independent review where applicable.
- SHA-guarded merge **only** to `Foodex_V2`; record PR, tested HEAD, merge SHA and CI links in issue closure.
- Final manual cross-persona/staging acceptance deferred to #2503; leaf closure only for fully verified code.
