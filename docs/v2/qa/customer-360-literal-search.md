# V2 #3597 — Customer 360 scoped literal search acceptance

Parent #2503 / #2818 P082. **PR targets Foodex_V2 ONLY; never main, production, tags, release APKs or cPanel.**

## Source, authority, and reuse

- Reuse canonical Customer Service / CRM → Customer 360 index `admin.customer-360.index`; existing permission and tenant decision `Customer360Controller::actor/access/applyVisibility`. No new route, Sidebar item, tab, form or user-visible text.
- Previous `platform_customers.name/email/phone` index query bound `LIKE "%{q}%"` but treated typed `%` and `_` as SQL wildcards, returning unrelated customer snapshots. This is result precision, not an independently proven SQL injection or tenant escape.
- Index now escapes `!`→`!!`, `%`→`!%`, `_`→`!_` and binds the escaped substring to fixed-column `LIKE ? ESCAPE '!'` predicates, portable on SQLite and MySQL/MariaDB. Retain existing `origin_channel`, `registration_source`, `active`, store, registered date, 25-item pagination, order and query preservation.
- No writes, identity changes, permissions/entitlement/SoD changes, exports, API response changes or marketing audience expansion.

## Authenticated regression

`DashboardCustomer360Test.php` appended two real `RefreshDatabase` HTTP tests:

- Super Admin and Store A Retail Admin discover Customer name/email/phone literal `%`, `_`, `!`, Arabic and English normal names; exclude wildcard lookalikes and foreign Retail Store B while preserving allowed Super Admin inspection.
- Authenticated roleless direct-route denial (403). Search does not alter `platform_customers` or `audit_logs` counts.
- More than 25 matching records retain `q` in `nextPageUrl` and paginate correctly on page two.

## Five-surface impact matrix

| Surface | Classification | Reason |
| --- | --- | --- |
| Laravel Backend | Affected | Read-only authenticated scoped Customer 360 `index` predicate |
| Dashboard | Affected | Same Customer 360 list navigation, AR/EN, RTL/LTR, mobile responsive and filter states; search precision improved |
| Customer App | Not affected | No public API, DTO, route, identity or Flutter change |
| Driver App | Not affected | No driver API, scope, delivery or Flutter change; B2C = DRIVER ONLY unchanged |
| Van App | Not affected | No Van API, scope, trip or Flutter change; B2B = VAN ONLY unchanged |

## Completion gates

- [ ] PHP syntax/Pint/PHPStan, Composer audit/OpenAPI, full Laravel/security/RBAC tests and AR/EN localization on exact final PR HEAD
- [ ] MySQL/Redis install/upgrade/recovery where applicable
- [ ] Repository Policy, FOODEX V2 Isolation and terminal FOODEX Required CI = SUCCESS
- [ ] Independent review, check active ownership and PR base/mergeability; SHA-guard merge exclusively into `Foodex_V2`
- [ ] Close atomic implementation leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only with tested head/merge SHA and CI URLs; final manual UAT remains #2503
