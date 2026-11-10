# V2 #2553 / Sidebar P223 — Units and Reference Values

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P223, parent #2503.

## Placement decision and user journey

**Reuse**, not a duplicate screen: `Catalog → lookup_management` in the existing Dashboard Sidebar, route `admin.lookups.index` (`/admin/lookups`), then the **Units of Measure** page-level tab (`?type=units`). This route is already implemented in `LookupManagementController` and `admin.lookup-management` (AR/EN, RTL/LTR, modal create/edit, responsive tabs/cards and pagination), and discoverable through the existing Catalog group. The same page also contains Brands reference values. Neither a second sidebar item nor a new controller/API is warranted.

**Navigation descriptor:** domain Catalog/Master Data; entity Unit; personas B2B_ADMIN, RETAIL_INVENTORY, B2C_STORE_ADMIN; entry type SIDEBAR → PAGE_TAB; canonical route `admin.lookups.index`; context global B2B or authorized Retail store; navigation owner `AdminNavigation`; read capability `lookups.view`; write `lookups.manage`; fallback is Catalog hub; RTL/LTR and mobile layout delegated to existing shared page.

## Authority and actual HTTP enforcement

| Actor / scenario | Sidebar and page | Real boundary |
| --- | --- | --- |
| B2B_ADMIN | Catalog lookup link → Units tab | `lookups.view` global. `LookupScopeService::visible` returns global and B2B units, not Retail store rows |
| RETAIL_INVENTORY at store A | Same link → Units tab | Store-scoped `lookups.view` permits A rows only. Foreign store B query returns 404; attempting unit creation returns 403 (no `lookups.manage`) |
| B2C_STORE_ADMIN at store A | Same link and create action | Valid A unit can be persisted and is audited with `lookup.unit.created`; B mutation is 404, forging B2B scope is 403 |
| B2B_ADMIN with explicit user DENY for `lookups.view` | Link suppressed even when role presentation is forced visible | Direct `GET /admin/lookups?type=units` returns 403, no new grant or RBAC audit |
| Inactive B2B_ADMIN role | Link suppressed | Direct GET returns 403; no route is authorized by old role or layout preference |

The canonical guards are `User::hasPermission` / Gates and `LookupScopeService` with `TenantContextResolver`. Dashboard middleware additionally rejects users without an active Dashboard role. Page visibility and page write privileges are intentionally distinct. The scope of these **non-ERP Master Data** routes does not require an unrelated ERP Module Entitlement or ERP readiness attestation; inventing one would incorrectly block normal reference data management. A disabled role is denied, independently of presentation flags.

The executable integrated proof is `backend/tests/Feature/V2UnitsLookupsSidebarHttpScopeTest.php`, using `CoreReferenceSeeder`, disposable users/store rows, both AR/EN HTML directions, direct HTTP positive/negative cases, and audited authorized unit creation. Existing `LookupManagementCenterTest` independently exercises lookup schema, duplicates, images, delete/deactivation, support access, product FK safety and responsive Blade structure. No privileged role/user or production data is altered.

## Surface-by-surface impact

| Surface | Impact | Evidence |
| --- | --- | --- |
| Laravel Backend | Added regression tests only | Existing web routes/Gates, scope resolver, validation, audit and persistence reused unchanged; no new migration or API v1 contract |
| Dashboard | Existing page/Sidebar retained | No view, CSS, JS, translation or route change; AR/EN and RTL/LTR HTTP is under test, real browser responsive/a11y remains in final V2 acceptance |
| Customer Flutter | Not affected | No product unit serializer, public endpoint, app UI, inventory or order changes |
| Driver Flutter | Not affected | No delivery/assignment or B2C Driver-only API changes |
| Van Flutter | Not affected | No van inventory/visits/dispatch, B2B Van-only or collections changes |

## Delivery and approval boundary

Issue-scoped PR **base `Foodex_V2` only**. Require exact-final-HEAD Repository Policy, V2 Isolation, Required CI, full Laravel Backend tests, Pint/PHPStan/security/OpenAPI, localization AR/EN, and MySQL/Redis fresh-install/upgrade/recovery plus terminal `required-ci-gate` all SUCCESS. Do not call skipped Flutter jobs successful tests.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under owner's latest #2503 instruction. The consolidated real browser UAT (320/360/390/412/430/768/1024/1280 responsive, keyboard focus/screen-reader, cross-role and manual datasets) is owned by final program #2503 / UX #2750; it has not been performed as part of this PR. No `main`, release, tag, production ZIP or cPanel deployment.
