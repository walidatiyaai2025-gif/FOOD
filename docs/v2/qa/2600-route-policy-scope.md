# P270 Routes — V2 #2600: routing policy store-ownership boundary

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / P270; parent #2503. **V2 isolation:** merge to `Foodex_V2` only, never `main`/release/production.

## Canonical journey and placement

- **Domain/entity:** Fleet & Field Operations / versioned routing policy and route decision rule.
- **Actor:** Super Admin and authorized platform-wide B2B/Operations operators, plus explicitly B2B store-assigned routing operators.
- **Goal:** view permitted routing configurations, draft own-store B2B policies, publish/rollback and safely simulate a permitted policy; review policy versions.
- **Placement:** **Reuse** existing Field Operations > Routing sidebar `field_ops_routing`. Existing `admin.field-operations.routing` (GET), `admin.field-operations.routing.store` (POST), `admin.field-operations.routing.action` (POST). There is no new route, page, sidebar or data model.
- **Navigation:** existing `field_operations` group; authorized by `territories.manage` or `field_ops.manage`; route and action use the same effective permission guard. Exact records addressed by existing policy ID, which must also pass backend ownership.
- **Surface:** shared Laravel Dashboard Field Operations page, desktop/tablet/mobile web, AR/EN selected locale and RTL/LTR; no markup, CSS or localization key change. Existing map/route builder with labelled lookups, accessible controls and 25-row server pagination remains unchanged.
- **Inputs:** mode is enum; store/territory/van references are authoritative lookups; rule conditions/actions use existing typed builder; raw JSON restricted to Super Admin.
- **Data model:** `routing_policies` versions are globally keyed by `code`, not owned by a `store_id` column. Therefore permission-only filtering or direct route model binding was insufficient to prevent a limited B2B actor from inspecting other operators' policies or retiring a foreign version.

## Scoped policy hardening

1. A limited routing actor's policy grid now lists only policies created by that actor. Platform managers (Super Admin, B2B Admin, Operations, Finance, where routing permission separately exists) retain current global visibility.
2. Limited actors can create only policies whose **every** rule has a typed `store_id` condition referencing a currently authorized active B2B store; unscoped rules including just `channel=b2b` are rejected. An inactive/foreign/Retail store is rejected by the pre-existing typed store validator.
3. The globally versioned `code` cannot be reused by a limited actor when **any prior version** is owned by another actor or has non-owned/unscoped rules. Existing owners may version their own B2B-scoped policies.
4. Before publish, rollback and simulate, route-model binding is not treated as authorization. A limited actor must own the requested policy, every rule must be scoped to an authorized B2B store, and **every version sharing the policy code** must pass the same check. Foreign/unguarded actions return HTTP 404, not policy details or a write.
5. The guard is executed server-side before service mutation. No new grant, permission, database migration, runtime dispatch mode, rule schema, or mobile REST API is introduced.

## Regression evidence

`backend/tests/Feature/V2RoutingPoliciesStoreScopeTest.php` verifies:

- Two custom B2B operators with different stores: AR/EN, RTL/LTR, one canonical sidebar route, own policy visible, foreign policy concealed, global owner sees both.
- Limited actor cannot submit global/unscoped routing conditions, create an unauthorized version of an existing global policy, or mutate a global policy.
- Guessing a foreign draft/published policy ID cannot publish, rollback, or simulate it; status, policy count and audit trace row count remain unchanged.
- Valid limited operator can create an own-store policy, publish and rollback its versions on existing routes.
- Additional existing `V2VanRoutingHttpAuthorityTest.php` (#2601 merged) covers roleless/retail/inactive direct GET/POST denial, forbidden B2C and foreign-store typed selector values, and route/Sidebar parity. P270 does not duplicate that delivery.

## Customer / Driver / Van impact matrix

| Surface | Impact | Verification/contract |
| --- | --- | --- |
| Backend | **Affected** | Dashboard-only policy read/write authorization; no database schema or REST response changes |
| Dashboard | **Affected** | Existing routing policy grid is scoped; existing AR/EN and RTL/LTR responsive layout, form and actions reused |
| Customer | **Not affected** | No Customer Flutter code, commerce auth, navigation, order or API changes |
| Driver | **Not affected** | No Driver Flutter or B2C dispatch API changes; B2C remains Driver-only |
| Van | **Indirect control-plane effect** | B2B routing policy administration is more restrictive; no Van Flutter/API contract changes, B2B remains Van-only. Historical policies and runtime routing decisions are untouched |

## Definition-of-Done and residual acceptance

Exact-head checks required: Repository Policy, V2 Isolation, FOODEX Required CI terminal gate, Laravel focused/full suite, PHP syntax/Pint/PHPStan, Composer audit, OpenAPI, AR/EN, real MySQL/Redis install-upgrade-recovery as scheduled. Mark only actual CI results as passed, do not claim device/browser testing or production behavior without evidence.

P270 may still require independent final UX/a11y, module entitlement/readiness and route-planning coverage beyond these specifically versioned routing policies; those must be tracked under master #2503 / UX gate #2750 rather than inferred from a single hardening PR. Manual UAT is deferred to V2 final; missing required automation is **not** waived.