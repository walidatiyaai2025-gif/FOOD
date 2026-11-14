# V2 #3580 — scoped Customer Groups literal name filter

## Source and authority
- Parent #2503, granular route/query scope #2818. Existing Dashboard routes `admin.b2b.customer-groups.index` and `admin.b2c.customer-groups.index`, `CustomerGroupManagementController::index`.
- Authorized store and channel are selected by `OperationalTenantScope::assertStore` and the existing `customers.view` permission. No new route, permission, role, Sidebar, public API or mutation is introduced.
- Historical implementation bound `q` as `LIKE '%q%'` with user-provided `%` and `_` unescaped, unintentionally matching wildcard lookalikes. The new bounded query escapes `!`, `%` and `_` and uses `name LIKE ? ESCAPE '!'` with parameter binding, portable to SQLite and MySQL/MariaDB even under differing SQL backslash modes.

## HTTP test coverage
`CustomerGroupManagementTest::test_customer_group_search_treats_percent_underscore_and_bang_as_literal_with_store_scope`:
- True literal `%_` matches only the intended Wholesale group, not wildcard-lookalikes; escape marker `!_` and plain `!` work.
- Ordinary `Gold` still returns both intended group names; an absent literal query returns none.
- Retail store A sees its literal `%_` group only, not same-store wildcard lookalike nor sibling store B's name. Direct B2C group index for unassigned store B rejects, as does an active roleless caller.
- Group count remains unchanged after searches. Existing sorting, members and group mutations are untouched.
- No claims about historical live user/account states or production incidents; this is a source-level read-accuracy fix.

## Surface impact
- **Dashboard (B2B/B2C Customer Groups):** existing authorized group list; search results more accurate; same group metadata/pagination/AR/EN translation keys/RTL/LTR/responsive view.
- **Backend:** query-only change to one Laravel Admin controller, using existing channel and store authority.
- **Customer app:** no mobile API, state, login or Flutter difference.
- **Driver app:** no B2C dispatch, wallet, API or Flutter difference.
- **Van app:** no B2B route, custody, API or Flutter difference. B2B=VAN ONLY; B2C=DRIVER ONLY remain intact.

## CI, release and acceptance
- Original Issue #3580, issue-scoped branch `fix/3580-v2-customer-group-literal-name-search`, one PR targeting `Foodex_V2` only.
- Require final PR-head Repository Policy, FOODEX V2 Isolation Gate and terminal FOODEX Required CI SUCCESS for Laravel, PHP syntax/Pint/PHPStan/security/OpenAPI, Arabic/English and MySQL/Redis acceptance.
- Merge and close this scoped programming leaf only once tested-source acceptance is verified; parent #2503/#2818 remains OPEN for full P082/manual UAT. **No main, tags, release/setup or update ZIP distribution, cPanel, or production.**
