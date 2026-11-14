# V2 #3587 — Scheduled Notification Campaign literal search

## Scope and source
- Parent #2503. Existing authorized `GET /admin/notification-campaigns` (Administration → Scheduled Promotional Campaigns), `management.dashboard` and `notifications.view` authority. Target PR `Foodex_V2` only; never `main`, release, tags, production or cPanel.
- Existing `index` uses `LIKE '%<user-q>%'` against campaign `name`, `title_ar` and `title_en`. Bound parameters prevent SQL injection, but percent/underscore are SQL wildcard tokens, producing unrelated search results. Not evidence of tenant permission bypass.

## Implementation
- Escape `!`, `%`, `_` in input before wrapping intentionally with substring `%`, and use bound `LIKE ? ESCAPE '!'` on the three fixed columns. Portable on supported SQLite and MySQL/MariaDB.
- Preserve existing 20-row pagination, status filter, sort, Arabic/English, RTL/LTR, route, Sidebar placement, `visibleCampaigns` store/channel constraints, review/approval/dispatch flows, and all API/notification payloads.
- `backend/tests/Feature/V2PromotionalCampaignLiteralSearchTest.php`: authenticated `RefreshDatabase` HTTP regressions across name/AR title/EN title in both locale modes, literal `%_!` true hit and wildcard lookalike exclusion, normal search, draft/active filtering, Retail store A vs store B isolation, unprivileged direct URL 403, no mutation on GET.

## Cross-surface impact
- Backend and Dashboard existing campaign listing affected (no Blade/JS markup change). Customer, Driver, Van public API and Flutter unchanged: no navigation, assignment or payload change. B2B VAN ONLY, B2C DRIVER ONLY unchanged. No schema/seed/auth entitlement changes.
- Final manual UI/staging acceptance belongs to master #2503 after the exact source and automated validation are complete; not a substitute for Required CI.

## Exact-head evidence
- To be filled after PR: Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI terminal success (Laravel, security/Pint/PHPStan, AR/EN, MySQL/Redis and changed-area jobs). Remain draft/open until verified.
