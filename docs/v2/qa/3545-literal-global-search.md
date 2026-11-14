# FOODEX V2 #3545 — Scoped global search literal substring matching

- Source line: V2 P082 action-scoped Dashboard discoverability, master #2503; existing #1717 global-search foundation remains unchanged.
- Integration: `Foodex_V2` ONLY. Never `main`, tags, releases, production, cPanel or APK publication.

## Source discrepancy

Both canonical global search providers previously interpolated the user query as a SQL LIKE pattern. Embedded `%` and `_` were wildcard operators, so a literal query could return unexpected orders or organizations (false-positive result discovery). Existing server-side order store-scope (`OperationalTenantScope::allowedStoreIds`) and organization `platform.manage` authorization remain intact; no authentication bypass has been established.

## Implementation and SQL portability

- New internal `LiteralLikePattern::contains` escapes `!`, `%`, and `_` with the explicit `!` SQL escape marker before wrapping the literal term with the intentional leading/trailing substring wildcards.
- `OrderGlobalSearchProvider` and `OrganizationGlobalSearchProvider` use parameter-bound, fixed-column `LIKE ? ESCAPE '!'` expressions; unlike implicit backslash escaping this is explicit on supported SQLite tests and MySQL/MariaDB production.
- Existing numeric `#id` exact match remains a separately authorized OR predicate; store and platform permissions, ordering/limits, and response shapes are unchanged.
- No user-supplied string is interpolated into SQL syntax; only bound pattern values are variable.

## Automated acceptance

`backend/tests/Feature/EnterpriseGlobalSearchTest.php` has new authenticated HTTP regressions:
- Literal `%` + `_` in order numbers matches an exact scoped result, does not match alternative wildcard records or the same number in another store.
- Literal escape marker `!` and `_` round trip without wildcard expansion; nonexistent literal query stays empty; `#id` lookup continues working.
- Organization name/code with literal wildcard or escape marker returns the exact authorized organization, not false positives; a store-scoped user cannot discover platform-only organizations.

Existing regression covers ordinary query matching, order limits, foreign store exclusion, organization platform scope and bilingual header.

## Cross-surface impact

| Surface | Impact | Contract |
|---|---|---|
| Dashboard existing global search | Affected; same entry point, more precise literal results | Same JSON, AR/EN strings, RTL/LTR, focus/navigation |
| Laravel authorization and tenant boundaries | Unchanged | Store-scoped orders and platform-managed organizations still enforced |
| Customer app | No public API or Flutter change | No changed Customer routes, payloads or identity |
| Driver app | No public API or Flutter change | No changed Driver routes, payloads or dispatch |
| Van app | No public API or Flutter change | No changed Van routes, payloads or dispatch |

## Final gate
PHP lint/Pint/static/security, focused and full Laravel tests, localization, real MySQL/Redis acceptance as applicable, exact-final-HEAD Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate and terminal `required-ci-gate` must all pass before an SHA-guarded `Foodex_V2` merge. Record merge source SHA, integration SHA and CI links. Defer manual/staging UAT to #2503; no production release claims.
