# V2 #3566 — Literal search for Enterprise Action Center approvals

## Scope and authority
- Parent: V2 master #2503, existing My Work / Action Center contract #1912, P082 page-action discoverability. Integration: `Foodex_V2` only. No main, release, signed ZIP/APK, cPanel or production deployment.
- Existing authorized entry point: `GET /admin/action-center?q=<text>`, normal My Work navigation. The operator still requires `AdminNavigation::canAccessMyWork` and all queue items are filtered by `EnterpriseActionCenter::canDisplay` after candidate selection. Mutation remains re-authorized by `WorkflowEngine`.
- Reuse existing page and query behavior, no new navigation/permission/key/locale, and do not change the bounded candidate cap (200), pagination or status filters.

## Current-source finding
Before this change `EnterpriseActionCenter::page()` wrapped user-provided search in `'%'.$search.'%'` then used `LIKE` on four fixed columns (step_code, workflow_key, entity_type, entity_id). SQL interpreted user `%` and `_` as wildcards rather than intended search text, admitting false-positive approval search results **within existing server-side access boundaries**. This is a static reproducible search-accuracy defect, not a claimed successful unauthorized approval or production incident.

## Implementation
- Create a bound SQL LIKE literal pattern by escaping `!` to `!!`, `%` to `!%`, `_` to `!_`, wrapping with the intentional leading/trailing substring markers.
- Apply an explicit `ESCAPE '!'` to all four `LIKE ?` predicates to avoid dependence on backslash SQL mode across supported SQLite and MySQL/MariaDB.
- No interpolation of user values into SQL syntax and no changes to step/workflow query order, ORM authorization or result DTO.

## Automated evidence
`backend/tests/Feature/EnterpriseActionCenterTest.php` extends existing `RefreshDatabase` and `WorkflowEngine` HTTP fixtures with:
- Authenticated B2B approver searches for literal `%` and `_` in entity references; false-positive wildcard-like references must not appear.
- Literal `!` marker, ordinary query, unmatched literal and no-write assertions.
- Authenticated B2C store approver sees only the authorized store action when both own and foreign store requests contain the same literal `%_` search term.
- Existing maker-checker, privilege denial, AR RTL, safe links, bounded queue and decision retry tests remain unchanged.

## Impact matrix and product behavior
| Surface | Classification | Proof |
| --- | --- | --- |
| Backend Dashboard Action Center | **Affected** | Existing scoped approval search, identical route, data/permissions, sorted paginated rendering |
| Dashboard visuals/AR-EN/RTL-LTR | **Unchanged** | No template/translation/CSS/JS/URL/empty-state change; existing bilingual tests and shared components |
| Customer Flutter | **Not affected** | No Customer API/DTO/route or app files |
| Driver Flutter | **Not affected** | No Driver API/DTO/dispatch or app files; B2C DRIVER ONLY |
| Van Flutter | **Not affected** | No Van API/DTO/custody or app files; B2B VAN ONLY |
| Finance/SoD/approval writes | **Not affected** | Read-only candidate pattern; WorkflowEngine remains write authority |

## Integration gate
No merge unless latest exact PR head has all required Repository Policy, FOODEX V2 Isolation, FOODEX Required CI terminal success, including Laravel full HTTP suite, PHP syntax/Pint/PHPStan/security, AR/EN, MySQL-Redis tests as applicable. Document tested SHA, merge SHA and CI URLs in Issue. Manual/staging UAT remains deferred in #2503; this leaf does not authorize main or production.
