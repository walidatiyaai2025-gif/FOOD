# V2 #3605 — Scoped literal Supplier Directory prefix search

**Source:** P239 / already integrated #2569 Supplier Sidebar; P082 authorization, parent #2503. **Target:** `Foodex_V2` only; no `main`/production/release/tag/cPanel or APK.

## Verified read-accuracy gap

`SupplierDirectory::paginate` previously removed user `%` and `_` characters before applying six-column bound SQL `LIKE`. This collapsed `Percent%_!` to `Percent!` (or `%` alone to an empty filter), showing unrelated or every visible supplier instead of a literal prefix. This is *not* evidence of SQL injection or an authorization bypass. Existing `authorizedScope` and `visibleQuery` remain the enforceable organization/company/store/tenant permissions.

## Reuse and change

- **Navigation:** Procurement & Inventory → canonical Procurement Workbench → Suppliers tab (`admin.erp.procurement.index?tab=suppliers`). REUSE existing live route, Data Explorer, scope selector and approved Dashboard navigation. No menu/page/API/schema/permission change.
- **Search:** fixed `code`, `name_en`, `name_ar`, `email`, `phone`, `tax_registration_number` columns, bound prefix `LIKE ? ESCAPE '!'`. Encode literal `!` to `!!`, `%` to `!%`, `_` to `!_` and append only intentional suffix `%`; do not change prefix matching to substring.
- **Authority:** preserve `procurement.suppliers.view`, module readiness/entitlement and effective store/organization/company authorization, status and scope filter conjunction, 10–100 per-page limits, stable name/id order, as-is AR/EN, RTL/LTR, responsive UI.
- **Security and accounting:** no data writes or API contract delta, no supplier create/update/archive/document changes or finance maker/checker/ledger impact.

## Executable regression coverage

- `EnterpriseSupplierFoundationTest.php`: `RefreshDatabase` service-level allow test for all six field prefixes, literal `%`/`_`/`!`, false positives, AR/EN prefixes, empty-result special-only, ordinary text, active-only scope, foreign Retail store and no audit mutation. Existing tests already exercise other-organization denied supplier reads, create/update/archive and per-page clamp.
- `EnterpriseProcurementWorkbenchTest.php`: real authenticated GET `admin.erp.procurement.index` supplier tab via original seeded B2C permission/role/module; literal bilingual name search and unescaped lookalike exclusion, filter/query-string preservation, foreign-store 403, zero read audit writes. Existing workbench tests separately cover module-disabled fail-closed and complete tab lifecycle.

## Impact matrix

| Surface | Impact | Explanation |
| --- | --- | --- |
| Laravel Backend | Affected | Prefix-search matching within already scoped Supplier Directory |
| Dashboard | Affected | Same Procurement suppliers tab; only result precision changes |
| Customer App | Not affected | No public Customer API, identity, auth or Flutter change |
| Driver App | Not affected | No Driver API, delivery, finance or Flutter change; B2C=DRIVER ONLY |
| Van App | Not affected | No Van API, route/custody/stock or Flutter change; B2B=VAN ONLY |

## Exact-head delivery gates

- [ ] Independent source review + no active worker file collision
- [ ] Repository Policy, FOODEX V2 Isolation Gate and terminal Required CI all SUCCESS
- [ ] PHP syntax/Pint/PHPStan, Composer audit, OpenAPI, AR/EN, full Laravel security/RBAC, MySQL/Redis install/upgrade/recovery as applicable
- [ ] Exact-final-HEAD SHA guarded PR merge **Foodex_V2 only** and verify integration commit, issue closed CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED with PR, CI, source and integration SHAs. Master #2503 remains open pending final manual/staging acceptance.
