# FOODEX V2 #2593 / P263 — Vans Sidebar / HTTP authority

- Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P263 (Fleet & Van); parent #2503.
- Integration: **Foodex_V2 only**. No main commit, PR, merge, tag, release or production deployment.
- Worker: issue #2593, branch `test/2593-v2-vans-sidebar-http-authority`; exactly one implementation PR.
- Placement: **Reuse**. No new screen or route. Domain: Fleet / Field Operations; entity: Van; actor: authorized B2B/Wholesale fleet reader; goal: find and inspect the canonical Vans registry; workflow: daily list/detail; existing parent: Field Operations navigation; navigation type: `SIDEBAR`; established page: `admin.field-operations.vans`. Dashboard Mobile Web uses the same permission-filtered off-canvas sidebar; AR/RTL and EN/LTR share the existing UI components. Fallback/back path: Field Operations overview, or Vans list from van details. Existing page state: real paginated Van registry, empty state and detail/action links.

## Route/permission map

| Action / entry point | Canonical authority | Guard and business constraints |
| --- | --- | --- |
| `field_ops_vans` Sidebar | `AdminNavigation::groupsFor` → `admin.field-operations.vans` | `drivers.b2b.view` **global effective permission**; role grants remain subordinate to user-level explicit DENY |
| `GET /admin/field-operations/vans` | `FieldOperationsController::vans` | `management.dashboard` + `drivers.b2b.view`; reads actual Van records, assignments, locations and warehouses |
| `GET /admin/field-operations/vans/{van}` | `FieldOperationsController::showVan` | Same `drivers.b2b.view` read authority; no B2C Driver fallback |
| `POST /admin/field-operations/vans` | `FieldOperationsController::storeVan` / `VanRegistryService` | Separately requires `drivers.b2b.manage`; read-only users cannot create a Van |
| `POST /admin/field-operations/vans/{van}/suspend` | `FieldOperationsController::suspendVan` | Separately requires `drivers.b2b.manage` |
| Channel/tenant | global B2B fleet registry, **not** a Retail store-list | Retail store roles do **not** authorize the global B2B Vans page; the Van registry currently has no `store_id` field, so no fictitious per-store filter is claimed |
| Entitlement/readiness | canonical core Field Operations route | Not an optional enterprise ERP module; the existing Dashboard session, active-account, route and permission gates remain authoritative |

**No new permission, role, module enablement, controller, route or visibility policy is introduced.** This task verifies the existing real behavior, rather than creating a second Van screen or allowing a UI-only link to bypass HTTP 403.

## Regression coverage

`backend/tests/Feature/V2VansSidebarHttpAuthorityTest.php` provides Laravel request tests against the actual canonical routes:

1. A synthetic global B2B read role discovers the Vans Sidebar item and opens the real populated Van registry in Arabic/RTL and English/LTR (200), with correct `navContext`, while a write request receives 403 and creates no record.
2. `drivers.tracking.view`, `customers.view` and a role with no grants cannot gain Vans registry access merely through another Field Operations navigation entry.
3. Effective multi-role `drivers.b2b.view` ALLOW can open the real page; a subsequent explicit **global DENY** removes both navigation and HTTP authority.
4. Retail-store-scoped operations and mobile B2C Driver identities cannot access the B2B fleet registry or get its Sidebar link.
5. Inactive wholesale users are rejected by the Dashboard HTTP gate.

The test creates **synthetic fixtures only** through `RefreshDatabase`. No live personnel, store or Van accounts are modified.

## Mobile impact assessment

| Surface | Classification and evidence |
| --- | --- |
| Customer Flutter | **NOT AFFECTED:** no Customer APIs, app routes, auth, pricing, notification or order contract changes |
| Driver Flutter | **NOT AFFECTED:** no Driver app/API, B2C dispatch, identity or tracking contract changes; the test confirms a B2C_DRIVER identity cannot open the management Vans route |
| Van Flutter | **NOT AFFECTED:** no Van runtime login, territory, visits, assignments, stock, direct sales, offline replay, notifications or fulfillment contract changes; only Dashboard authorization regression coverage is added |

Invariant unchanged: **B2B fulfillment → VAN ONLY; B2C fulfillment → DRIVER ONLY**. As no shared API/mobile contract is changed, no additional mobile issue is required.

## Evidence and acceptance

- Current source inspected: `backend/app/Support/AdminNavigation.php`, `backend/app/Http/Controllers/Admin/FieldOperationsController.php`, `backend/routes/web.php`, `backend/resources/views/admin/field-operations.blade.php`.
- Scope ownership is restricted to this **new test and QA document**. Other V2 workers own shared `AdminNavigation.php`; no overlapping edits.
- PHP syntax was checked independently before the first remote code commit. Full Laravel tests and required checks must be recorded from the exact final PR SHA, not asserted ahead of their actual GitHub run.
- Current reused UI includes existing AR/EN localized page heading/empty state, shared Dashboard Responsive CSS and off-canvas navigation. **Real-browser visual/a11y acceptance at 320/360/390/412/430/768/1024/1280 px is NOT verified by this test**.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under owner decision on #2503. Do not claim the complete product UAT or production deployment.
- Merge only after Repository Policy, FOODEX V2 Isolation Gate and the exact-head Required CI Gate and all applicable child jobs succeed. Preserve original issue branch and one PR; close #2593 only when the full scoped Definition of Done is genuinely evidenced.
