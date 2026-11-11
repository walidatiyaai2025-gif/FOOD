# FOODEX V2 #2725 / T356R8 — Routing & Routes Sidebar migration

**Source:** FOODEX Unified RBAC / ERP / Sidebar (2026-11-09) T356R8; parent #2503. **Exclusive integration target:** `Foodex_V2`. Never main, release, tags or production deployment.

## Placement, source and boundaries

Reuse the existing `field_ops_routing` navigation item in the existing `field_operations` business group. Approved labels are **الأسطول والعمليات الميدانية → التوجيه والمسارات** and **Fleet & Field Operations → Routing & Routes**. The canonical existing route is `admin.field-operations.routing` (GET), served by `FieldOperationsController::routingPolicies`. Related `admin.field-operations.routing.store` (POST) remains a separately protected mutation, not an extra Sidebar item. No new group key, route, permissions or controller.

The visible Sidebar route uses canonical `routeItemAny` with `territories.manage` OR `field_ops.manage`. The actual controller independently enforces the same permission pair for viewing and state-changing requests, behind Management Dashboard access; changing translation strings does not grant permission. The existing service remains the authority for VAN-only B2B routing, permitted B2B stores, rule validation, territory/van lookups, and rollback. No raw JSON or business ID field was added.

**Navigation resolution:** domain=Field Operations, role=authorized global operator, user goal=review routing policies, canonical route=`admin.field-operations.routing`, navigation owner=`field_operations`, navigation type=SIDEBAR, parent surface=Dashboard, entry point=Routing & Routes, desktop/tablet/mobile=existing shared responsive navigation, RTL/LTR=existing UI, fallback=Field Operations Overview. Placement decision **REUSE**; new screen/sidebar key prohibited. Business fields remain unchanged.

## Acceptance evidence

`backend/tests/Feature/V2FieldOpsRoutingSidebarMigrationTest.php` validates exact registry/layout group, route name, key, labels, B2B operational permissions and no privilege drift; Arabic and English RTL/LTR for the actual authorized Dashboard route; exactly one Sidebar item with the original key; and direct read + create HTTP 403 for Retail, roleless or inactive users with routing-table immutability. Existing `FieldOperationsRoutingRuleBuilderTest` covers allowed/foreign B2B store conditions, inactive Van/territory lookup, structured rule builder and unauthorized writes. The source-retired `FieldOperationsAdminExposureTest` literals for old Sidebar group text are updated to the approved wording without weakening other assertions.

No new API, schema, seed, route, visual component, workflow, role grant, financial action or external integration. Existing loaded records, policies, store assignments and permissions remain unchanged.

## Customer / Driver / Van applicability

- **Customer:** no Flutter files, product/order API shape or authorization changes.
- **Driver:** no app code, GPS, Retail delivery, cash-collection or dispatch changes; B2C DRIVER ONLY remains enforced.
- **Van:** no app code, visit/offer/routing API or fulfillment change; B2B VAN ONLY is preserved. Only the Management Dashboard Sidebar wording changes.

No separate mobile issue is required when no app/API behavior changes. AR/EN translations are complete and share the existing responsive Dashboard; manual real-browser, keyboard, accessibility, viewport and RTL/LTR acceptance remains **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** per latest #2503 override and UX gate #2750, not a claim of independent UI-UAT completion.

**Required merge gate:** exact final-head Repository Policy, V2 Isolation, Laravel full tests/Pint/PHPStan/security/OpenAPI, AR/EN, MySQL Redis fresh-install/upgrade/recovery, terminal `required-ci-gate` all green. Merge exclusively into `Foodex_V2`, then verify and close this leaf if scope complete.
