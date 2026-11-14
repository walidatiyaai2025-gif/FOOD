# FOODEX V2 #3579 — System Lookups literal search

## Authoritative scope
- Existing Central System Lookups feature #832 / PR #845, V2 source-to-Issue master #2503.
- Canonical existing page `/admin/operations/lookups` (five in-page tabs including payment methods, statuses and pricing tiers), existing server-side view authority `SystemLookupController::authorizeView`, and Super Admin management restriction. **Reuse** existing UI and navigation; no new route/sidebar/page.
- V2 issue branch and PR integrate to `Foodex_V2` only. No main, tags, APK or production deployment.

## Source discrepancy
Unescaped user `q` in the existing authorized System Lookups page was bound as `LIKE '%...%'`, so percent and underscore acted as wildcard operators in labels, names and codes. This produces imprecise authorized search results; it is not SQL injection or an established cross-tenant leak.

## Implementation
- One escaped pattern built from trimmed `q` using explicit marker `!`: `!` → `!!`, `%` → `!%`, `_` → `!_`. Surrounding percent signs intentionally keep substring semantics.
- Fixed known columns use `LIKE ? ESCAPE '!'` and parameter binding for both `OperationalLookup` and `B2bPriceTier` filter branches, portable across SQLite and MySQL/MariaDB SQL modes.
- Preserve canonical types, status selection, 100-row pagination, priorities/sorting, routing, AR/EN, RTL/LTR, access control, all create/update/audit behaviors, and data fixtures.
- `V2SystemLookupLiteralSearchTest` has real authenticated Laravel `RefreshDatabase` HTTP regressions for literal %_/!_, Arabic and English, normal search, type/surface denial, active/inactive filters, exact tier matching and roleless 403; checks no lookup or tier labels are mutated by search.

## Cross-surface impact
| Surface | Impact | Contract |
|---|---|---|
| Laravel web controller | Affected read filter only | Existing view/management permission, no schema/write changes |
| Dashboard System Lookups | Same screen, tabs, filter row and action menus | Existing AR/EN, RTL/LTR, accessibility and responsive behavior |
| Customer app | No impacted Flutter code or public API payload | Lookup API and price-tier selection unchanged |
| Driver app | No impacted Flutter code or public API payload | B2C dispatch contract unchanged |
| Van app | No impacted Flutter code or public API payload | Wholesale Van dispatch/custody contract unchanged |

## Merge and acceptance
Require exact-final-head PHP/Pint/static/security, full Laravel, localization and real MySQL/Redis CI plus terminal required-ci-gate, Repository Policy and V2 Isolation Gate all SUCCESS. Integrate original issue-scoped PR to Foodex_V2 only with expected head SHA, record tested head/merge SHA/CI links; close this leaf only `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Keep #2503 open for comprehensive manual/staging/source acceptance.
