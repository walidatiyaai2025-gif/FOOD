# V2 #3593 — System Inspector literal diagnostic search acceptance

Parent #2503 / P082 #2818. **PR base Foodex_V2 only. No main, release, tag, APK, cPanel or production deployment.**

## Source and authority
- Existing authorized Admin Hub → System Inspector route `admin.inspector.index`, protected by `platform.manage` Gate in `platformAdmin`. REUSE the existing page, search filter, source/severity/app/channel/store filters, incident trend, 100-row event pagination, AR/EN, RTL/LTR and responsive layout.
- Existing bound search interpreted literal business text `%` and `_` as SQL LIKE wildcards over four fixed columns `message`, `route_name`, `url`, `correlation_id`. This is result-accuracy, **not demonstrated SQL injection or RBAC bypass**.
- `SystemInspectorController::index` now escapes `!`, `%`, `_` to `!!`, `!%`, `!_` and uses fixed-column `LIKE ? ESCAPE '!'` with bound, literal-substring search. No new API/route, schema, mutations, exports, data capture, navigation or UI text.

## Authenticated HTTP regression
- `SystemInspectorTest.php` adds a seeded `RefreshDatabase` Super Admin test for wildcard/escape literal matching in all four columns, positive AR/EN normal text, negative lookalikes, source/severity filtering, direct B2B Admin HTTP 403 and unchanged event/audit counts on GET.
- 101 synthetic diagnostic rows verify existing 100-row pagination and retained literal `q` on page two.
- No live diagnostic data/secrets or account identity copied into repository tests.

## Five-surface impact
| Surface | Classification | Rationale |
| --- | --- | --- |
| Backend | Affected | Existing read-only index SQL predicate only |
| Dashboard | Affected | Search results precision; existing authorized UI reused without markup changes |
| Customer App | Not affected | No Customer API/Flutter/JSON change; incident submission remains unchanged |
| Driver App | Not affected | No Driver API/Flutter/JSON change; B2C DRIVER ONLY preserved |
| Van App | Not affected | No Van API/Flutter/JSON change; B2B VAN ONLY preserved |

## Exact-final-head gates
- [ ] PHP syntax/Pint/PHPStan, Composer audit/OpenAPI and full Laravel HTTP tests
- [ ] AR/EN localization and MySQL/Redis install/upgrade/recovery where CI applies
- [ ] Repository Policy, FOODEX V2 Isolation and terminal Required CI = SUCCESS on same PR head
- [ ] Revalidate exact source/fence/PR base and no overlapping ownership; independent review and SHA-guard merge only to Foodex_V2
- [ ] Verified merge SHA/CI and leaf coding closure; final staging/production/manual UAT remains master #2503
