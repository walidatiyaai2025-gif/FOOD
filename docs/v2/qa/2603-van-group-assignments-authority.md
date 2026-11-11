# FOODEX V2 Sidebar P273 — Van business group and assignment authority

Issue #2603 | Parent #2503 | Sidebar source legacy P273, actual source DOCX paragraph P262 (Fleet & Van) | Integration target: Foodex_V2 ONLY.

## Reuse → Extend → Compose → New Screen decision

| Field | Verified canonical implementation |
| --- | --- |
| Business domain and entity | Fleet & Van; global B2B Van registry and assignment control plane |
| Personas | Global B2B Van Viewer, B2B Van Manager, Retail Store Admin, roleless, inactive and explicitly denied actors |
| User goal | Inspect Vans and assignments; managers can create and maintain B2B assignments without granting access to Retail or viewer-only accounts |
| Workflow and density | Regular assignment review, occasional Van/operator reassignment; existing 25-row paginated grid |
| Placement | REUSE existing Field Operations business-domain Sidebar, with separate field_ops_vans and field_ops_assignments child routes; no competing top-level Van group or duplicated ERP page |
| Navigation type | SIDEBAR (Field Operations) → domain hub Van list / assignments; Van detail CONTEXT_ACTION; assignment create/update WORKFLOW_STEP |
| Existing routes | admin.field-operations.vans, admin.field-operations.vans.show, admin.field-operations.assignments, admin.field-operations.assignments.store, admin.field-operations.assignments.update, admin.field-operations.assignments.destroy |
| Read authority | Effective global drivers.b2b.view, projected by AdminNavigation + AdminSidebarManager; FieldOperationsController independently checks the same ability on GET |
| Write authority | Independent global drivers.b2b.manage checked on POST/PATCH/DELETE. Read-only actor cannot mutate; VanRegistryService rejects overlapping active primary Van assignments within a transaction and writes audit evidence |
| Tenant/channel | Van and VanAssignment entities are GLOBAL B2B fleet control-plane records, not store-owned Retail resources. Retail-only roles never grant global B2B Van access; other API/visit/order flows have their own separate channel/store gates |
| Entitlement/readiness | Existing core live Van assignments page and registered routes; not a new, disabled or uncertified ERP module |
| Lookups/forms | Existing active Van and operator lookups, primary/backup assignment type and effective date selector; no new raw ID/JSON controls or business form |
| AR/EN, RTL and LTR | Reuses existing admin.field_operations and admin.nav_groups.field_operations translated copy, localized dir attribute, responsive Dashboard shell |
| Responsive/a11y | Existing Dashboard web page for phone 320px through tablet and desktop; no new markup, focus chain, controls or layout changes |
| Empty/forbidden/fallback | No authorized child means no Van group link. Direct unauthorized HTTP returns 403; overlapping primary assignment returns validated form error without duplicate; existing Field Operations Sidebar/back path |

This is a navigation-GROUP acceptance leaf and distinct from completed #2594 (vehicle registry actions) and #2590 (Fleet live map and tenant-filtered feed). Scope uniquely covers real Van assignment management and no duplicate navigation.

## Acceptance evidence — genuine Laravel HTTP and database

New backend/tests/Feature/V2VanGroupAssignmentsAuthorityTest.php runs seeded Laravel with synthetic users, Vans and VanAssignment records. It covers:

1. B2B read-only actor sees exactly one Van registry and one assignment entry under Field Operations and reaches both real pages with HTTP 200 in Arabic RTL and English LTR. Separate fleet tracking and visits are not granted.
2. Viewer cannot POST, PATCH or DELETE assignments; existing active assignment remains unchanged, no unauthorized row is created.
3. Manager with both global drivers.b2b.view and drivers.b2b.manage successfully posts the real assignment form, persists active primary assignment with loaded-work count and actor, and duplicate overlapping primary is rejected with a real validation error.
4. Retail Store Admin, roleless and inactive manager see no Van/Assignments links and get 403 on direct list/create routes, with no unauthorized assignments.
5. Global explicit per-user drivers.b2b.view DENY overrides role inheritance for both navigation and actual GET route.
6. Finance-only actor cannot inherit Van assignment management just by having a broad non-Van global role.

No production role/permission grants, runtime code, schema/migration, API or mobile app edits. All synthetic records roll back using RefreshDatabase.

## Component and cross-app impact

| Component | Verified impact |
| --- | --- |
| Laravel Backend/Dashboard | Additive issue-owned acceptance test for existing routes and server-side RBAC; no production file modified |
| Customer Flutter | Not affected: no Customer source, commerce/order/cart/account, mobile identity or API contract change |
| Driver Flutter | Not affected: no Driver source, B2C dispatch/assignment/proof/collection or API change |
| Van Flutter | Not affected by this patch: no Van app source, Van runtime login, mobile assignment/visit/stock/offline contract change; the test does not toggle allow_van_app or grant van.login |
| Queue/mail/storage/cache | No runtime modifications, tasks or migrations |
| Installer/Updater/Release | No APK/ZIP build/version/dependency/production deployment |

B2B = VAN ONLY and B2C = DRIVER ONLY remain unchanged. No mobile implementation issue is needed for a test-and-documentation-only delta.

## Gates and closure

Require Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate and all relevant jobs GREEN on the exact final PR HEAD, including Laravel full suite, PHP lint, Pint/PHPStan, security/OpenAPI, Arabic/English localization, MySQL/Redis fresh-install/upgrade/recovery acceptance and terminal required-ci-gate. Repair any red on the same branch and PR. Before merge verify owner lease, target base Foodex_V2, current head, changed-file scope, mergeability and all exact-head checks. Use SHA-guarded squash merge solely into Foodex_V2, verify merge commit and postmerge branch comparison, record CI and closure evidence.

MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL by owner #2503. Parent remains open pending whole-program audit. This QA note does not certify device UAT, signed APKs, production Setup/Update ZIPs or production operation.

No main integration, release/tag/version promotion, cPanel action or production deployment.
