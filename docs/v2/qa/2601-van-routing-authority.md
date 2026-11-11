# FOODEX V2 #2601 / P271 — التوجيه (Van Routing & Routes)

## Source and canonical navigation authority
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph P271; parent #2503. Integration branch **Foodex_V2 only**.
- Domain: Fleet & Field Operations; canonical business route `admin.field-operations.routing` (GET `/admin/field-operations/routing`), registered Sidebar key `field_ops_routing`, group `field_operations`, label `admin.field_operations.routing` (AR: التوجيه والمسارات; EN: Routing & Routes).
- Placement decision: **Reuse** existing authorized routing policies workspace in FieldOperationsController and `admin.field-operations` Blade (structured rule builder, existing policy listing, preview/simulation and lifecycle). Do not create a duplicate page, field, custom privilege or fake route.
- Actions: `admin.field-operations.routing.store` (POST draft) and `admin.field-operations.routing.action` (POST publish/rollback/simulate), all guarded server-side by either `territories.manage` or `field_ops.manage`. A role must separately satisfy Dashboard admission; just exposing the Sidebar cannot grant a policy.
- B2B VAN ONLY is invariant: typed `channel` field accepts `b2b` only; typed `store_id` validates against current actor's authorized **active B2B** store list; B2C Retail/Driver cannot use this route. Policy simulation is read-only; actual production routing traces are generated only by the published policy runtime.
- Registered group and route topology already covered by #2725 `V2FieldOpsRoutingSidebarMigrationTest`. New P271 tests specifically prove live HTTP access, denial of create/publish/simulate, and cross-store/channel write checks.

## Test and security matrix
| Case | Current contract / test evidence |
| --- | --- |
| Authorized field operator | `field_ops.manage` plus Dashboard admission; exactly one routing item in field operations, AR/EN labels and RTL/LTR page, GET 200 |
| No role, Retail store-only, inactive actor | No routing Sidebar entry, direct GET 403, POST draft/publish/simulate 403, zero unauthorized policy/decision-trace writes |
| Exact B2B store permitted | Scoped operator can see authorized B2B store selection and save one draft rule for it |
| Foreign active B2B and B2C store | Non-owned or Retail store IDs in rule builder are rejected; no corresponding draft exists |
| Retail channel input | `b2c` typed channel is rejected before creating any Van routing policy |
| Published policy / effective routing | Existing `RoutingPolicyServiceTest` validates Draft→Published lifecycle, rollback, deterministic simulated results and no simulated persisted trace |

## Data-scope limitation (do not overstate)
`RoutingPolicy` records currently represent **global routing policy versions**, not first-class per-store records. The canonical page lists global policy metadata, while individual typed `store_id` rule inputs are checked against actor store scope. P271 tests demonstrate permission and **write-input isolation**, not a claim that global policy listing is store-filtered. Before approving any future per-store routing-policy information-classification contract, establish an explicit global policy ownership/visibility model and a separate cross-store read isolation test. Do not invent `routing_policies.store_id`, hide existing global versions silently or claim proof of scoped record-level read isolation.

## Backend / Dashboard / Customer / Driver / Van impact
| Surface | Assessment |
| --- | --- |
| Backend + Dashboard | Additive Laravel HTTP regressions and QA traceability only; no production PHP, controller, route, nav, Blade, migration, policy schema, entitlement, queue or cache changes. Actual existing Dashboard route tested; no UI redesign. |
| Customer | **No code/API change**. Customer/Wholesale orders are routed by authoritative backend fulfillment, not by this test file; no Customer App contract altered. |
| Driver | **No code/API change**. B2C DRIVER-only execution preserved; direct Retail access to B2B Van policy workspace remains denied. |
| Van | **No code/API change**. Existing B2B-only routing decisions and Van field visits unaffected; checks only strengthen backend regression. No Van Flutter source touched. |

No new mobile Issue for tests/docs-only changes; Customer, Driver and Van Flutter CI may legitimately skip these files and such skips must not be reported as passing mobile tests.

## Governance / evidence
- Read current `AGENTS.md`, `docs/v2/V2_ISOLATION_GOVERNANCE.md`, .ai orientation/skill routing and original #2601, all PRs and worker leases.
- Existing #2598 / PR #3087 modifies FieldOperationsController **visits**; no edits to its file. #2713 / PR #3064 owns AdminNavigation; no edits to that file. All source changes are two new issue-scoped files.
- New `backend/tests/Feature/V2VanRoutingHttpAuthorityTest.php`: 3 integrated Laravel tests covering effective actor permission, AR/EN/RTL/LTR, active/inactive/roleless/retail, server-side create/publish/simulate denial, exact active B2B store and rejected Retail/foreign input.
- Existing `FieldOperationsRoutingRuleBuilderTest.php`, `RoutingPolicyServiceTest.php`, `V2FieldOpsRoutingSidebarMigrationTest.php` retained.
- CI and merge exact-SHA evidence belongs to the Issue/PR. Do not claim visual browser/staging UAT (owner #2503 postpones it to final V2 acceptance #2750). The absence of a new app code change is *not* evidence that actual Android builds ran.
- The present P271 source-backed contract is the canonical global routing-control-plane access and typed rule isolation; if the pending V2 governance later requires store-specific policy row visibility, implement it via separately coordinated issue rather than misrepresenting this test-only PR.
