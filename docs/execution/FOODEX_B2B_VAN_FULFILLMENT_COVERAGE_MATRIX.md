# B2B Van Fulfillment — No-Gap Coverage Matrix

Umbrella: **#1190**
Target: **B2B/Wholesale = Van fulfillment only; B2C/Retail = Driver fulfillment only**

Status vocabulary:

- `PLANNED` — required, not yet proven complete;
- `IMPLEMENTED` — source exists but final integrated proof is pending;
- `EVIDENCED` — final required runtime/test evidence exists;
- `BLOCKED` — explicit blocker;
- `N/A` — only with documented reason.

**Closure rule:** #1190 cannot close while any Critical/High row is not `EVIDENCED`.

| ID | Priority | Area | Required capability | Current baseline | Target evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |
| BF-001 | Critical | Policy | Channel determines fulfillment actor | Mixed historical Driver/Van assumptions | `FulfillmentActorPolicy`; `B2BVanFulfillmentMigrationAuditTest::test_channel_actor_contract_is_explicit`; PR #1209 | EVIDENCED |
| BF-002 | Critical | Routing | Customer App B2B order invokes Smart Routing | Shared post-create coordinator wired into Customer checkout | `OrderTerritoryRoutingServiceTest::test_post_create_coordinator_routes_supported_b2b_sources_and_never_falls_back_to_driver` + production path contract + PR #1212 / Required CI Gate #3925 (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-003 | Critical | Routing | Dashboard-created B2B invokes same routing | `AdminOrderManagementService` invokes shared post-create coordinator | shared-source feature + production path contract + PR #1212 / Required CI Gate #3925 (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-004 | Critical | Routing | Van-created B2B invokes same routing | Van order capture reuses `AdminOrderManagementService` and shared coordinator | shared-source feature + Van controller path contract + PR #1212 / Required CI Gate #3925 (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-005 | Critical | Routing | No Driver fallback for unresolved B2B | Unresolved post-create routing persists `awaiting_dispatch`; coordinator creates no Driver assignment | `test_post_create_routing_inability_persists_explicit_awaiting_dispatch` + PR #1212 / Required CI Gate #3925 (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-006 | Critical | Routing | B2C never auto-assigns Van | Van routing service accepts generic Order | `OrderTerritoryRoutingService` actor guard; `OrderTerritoryRoutingServiceTest::test_b2c_order_cannot_enter_van_routing_runtime`; PR #1209 | EVIDENCED |
| BF-007 | Critical | Dispatch | Manual B2B dispatch offers/accepts Van only | assignDriver + assignVan both exist | UI/API authorization tests | PLANNED |
| BF-008 | Critical | Dispatch | Manual B2C dispatch offers/accepts Driver only | mixed dispatch capability exists | `OrderManualDispatchService` actor guards; `OrderManualDispatchServiceTest::test_manual_dispatch_rejects_cross_channel_actor_assignment`; PR #1209 | EVIDENCED |
| BF-009 | High | Routing | Reassignment preserves history | Prior active Van assignment is marked `reassigned` and explicit reassignment audit is recorded | `test_reassignment_preserves_history_and_writes_explicit_audit` + PR #1212 / Required CI Gate #3925 (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-010 | High | Routing | Physically loaded/out-for-delivery work not silently rerouted | `picked_up` / `out_for_delivery` execution ownership locks automatic reroute | `test_picked_up_execution_blocks_silent_reroute_to_another_van` + PR #1212 / Required CI Gate #3925 (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-011 | Critical | Backend | Van assigned-order query includes Customer/Dashboard-created orders | `VanOrderReadService::queryForActor()` reads active `order_van_assignments` ownership, independent of visit scope | `VanOrderControllerTest::test_van_order_feed_uses_active_order_ownership_without_visit_scope`; PR #1215 / Required CI Gate #3933 on exact head `533735993cb0b5c0fc3eef0001c72dc56ff37ba5` (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-012 | Critical | Backend | Exact Van B2B Order Detail API | `GET /api/v1/van/orders/{order}` returns customer/items/invoice/payment/collection/timeline context | `VanOrderControllerTest::test_exact_van_order_detail_exposes_commercial_finance_collection_and_timeline_context`; PR #1215 / Required CI Gate #3933 on exact head `533735993cb0b5c0fc3eef0001c72dc56ff37ba5` (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-013 | Critical | Execution | Van acceptance | Van execution API persists `assigned -> accepted` against exact active `OrderVanAssignment` ownership | `VanDeliveryExecutionTest::test_van_executes_full_b2b_lifecycle_with_staged_delivery_proof`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-014 | Critical | Execution | Van picked-up | Van execution API persists `accepted -> picked_up` through the shared delivery transition contract | `VanDeliveryExecutionTest::test_van_executes_full_b2b_lifecycle_with_staged_delivery_proof`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-015 | Critical | Execution | Van out-for-delivery updates Order | Van `picked_up -> out_for_delivery` synchronizes authoritative Order status/history/audit through `DeliveryExecutionService` | `VanDeliveryExecutionTest::test_van_executes_full_b2b_lifecycle_with_staged_delivery_proof`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-016 | Critical | Execution | Van delivered updates Order | Van delivered transition synchronizes Order status and reuses shared inventory/proof/settlement gates | `VanDeliveryExecutionTest::test_van_executes_full_b2b_lifecycle_with_staged_delivery_proof`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-017 | Critical | Execution | Van failed delivery + configured reason | Van failure endpoint validates active configured reason codes/notes and synchronizes failed Order truth | `VanDeliveryExecutionTest::test_failed_delivery_uses_configured_reason_lookup_and_other_requires_note`; `test_failed_delivery_is_idempotent_and_retry_returns_order_to_out_for_delivery`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-018 | Critical | Execution | Van retry after failed | Van retry endpoint performs server-authoritative `failed -> out_for_delivery` and clears failure state | `VanDeliveryExecutionTest::test_failed_delivery_is_idempotent_and_retry_returns_order_to_out_for_delivery`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-019 | Critical | Execution | Van transition idempotency | Immutable `order_van_execution_events` ledger enforces assignment-scoped idempotency fingerprint/replay semantics | `VanDeliveryExecutionTest::test_failed_delivery_is_idempotent_and_retry_returns_order_to_out_for_delivery`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-020 | Critical | Execution | Reassigned/stale Van cannot mutate | execution ownership requires current runtime `van_assignment_id` to match active `OrderVanAssignment.van_assignment_id` | `VanDeliveryExecutionTest::test_stale_reassigned_or_cross_van_actor_cannot_mutate_order`; `test_same_van_runtime_reassignment_cannot_mutate_order_owned_by_stale_van_assignment`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-021 | Critical | Proof | Delivery proof required before delivered | Van supports staged immutable proof upload and shared delivered gate rejects delivery without current proof | `VanDeliveryExecutionTest::test_van_executes_full_b2b_lifecycle_with_staged_delivery_proof`; `test_delivered_requires_proof_even_after_required_collection_is_settled`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-022 | High | Proof | Proof appears in Dashboard timeline | Driver evidence exists | B2B Van timeline test + screenshot | PLANNED |
| BF-023 | Critical | Finance | Required collection blocks Delivered | Van delivered transition uses authoritative `DeliverySettlementService` and blocks while COD amount remains collectible | `VanDeliveryExecutionTest::test_delivered_is_blocked_until_authoritative_collection_is_settled`; PR #1220 / Required CI Gate #3947 on exact head `dc7c07bc7c5b795e28f2bd1a02b83c760c46feeb` (backend fast-fail + backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-024 | Critical | Finance | Van collects against authoritative invoice outstanding | VanCollection exists | over-collection/idempotency tests | IMPLEMENTED |
| BF-025 | High | Finance | Receipt generated and reachable | Receipt screen exists | runtime navigation + API evidence | PLANNED |
| BF-026 | Critical | Finance | Wallet/custody updates once | Van wallet domain exists | reconciliation test | PLANNED |
| BF-027 | Critical | Finance | Remittance preserves custody truth | Remittance exists | submit/review/reconciliation E2E | PLANNED |
| BF-028 | High | Finance | Account-credit B2B approval remains authoritative | current B2B approval exists | regression test | PLANNED |
| BF-029 | Critical | Van UI | Dashboard shows assigned/active B2B fulfillment | Van Dashboard exists | 360x800 + 430x932 evidence | PLANNED |
| BF-030 | Critical | Van UI | Existing Orders is canonical fulfillment home | Orders exists but read-only/basic | route authority + runtime evidence | PLANNED |
| BF-031 | Critical | Van UI | Orders filters cover new/active/ready/OFD/failed/completed/all | partial status rendering exists | widget tests + screenshots | PLANNED |
| BF-032 | Critical | Van UI | Exact Order Detail reachable normally | missing full canonical detail | route/navigation + screenshots | PLANNED |
| BF-033 | Critical | Van UI | Order detail shows items/customer/address/invoice/payment/collection/timeline | partial data available across domains | runtime + widget evidence | PLANNED |
| BF-034 | Critical | Van UI | Accept/Pickup/OFD/Delivered actions | not in Van Orders today | functional widget/E2E | PLANNED |
| BF-035 | Critical | Van UI | Failed/retry UX | not complete in Van | functional widget/E2E | PLANNED |
| BF-036 | Critical | Van UI | Proof capture/upload UX | not complete in Van | mobile runtime evidence | PLANNED |
| BF-037 | High | Van UI | Navigation to customer address | route/customer data exist | route/action evidence | PLANNED |
| BF-038 | Critical | Van UI | Collection -> Receipt -> Order return flow | screens exist separately | integrated navigation/E2E | PLANNED |
| BF-039 | High | Van UI | Route/Visit context links exact order | visit/order link foundation exists | functional navigation test | PLANNED |
| BF-040 | Critical | Van UI | No operational function hidden/API-only | multiple current screens exist | route inventory audit | PLANNED |
| BF-041 | High | Van UI | AR/EN + RTL/LTR | base localization exists | screenshot/widget evidence | PLANNED |
| BF-042 | High | Van UI | Responsive 360x800 + 430x932 | screenshot harness exists | exact-head artifacts | PLANNED |
| BF-043 | Critical | Notifications | B2B assignment push targets Van | Van notification shell exists | push payload + deep-link E2E | PLANNED |
| BF-044 | Critical | Notifications | B2B push never targets Driver | historical Driver B2B exists | negative routing test | PLANNED |
| BF-045 | High | Notifications | Reassignment/cancel/action-required update Van | notifier foundation exists | event/push tests | PLANNED |
| BF-046 | Critical | Tracking | B2B order resolves Van live location | fleet foundation exists | Dashboard/Customer tracking E2E | PLANNED |
| BF-047 | Critical | Tracking | B2C continues Driver location | Driver live tracking exists | regression E2E | PLANNED |
| BF-048 | High | Tracking | stale/offline Van explicit | fleet stale model exists | runtime evidence | PLANNED |
| BF-049 | Critical | Dashboard | B2B Orders shows assigned Van/routing/execution | mixed controls exist | Dashboard feature + visual tests | PLANNED |
| BF-050 | Critical | Dashboard | B2B has no Assign Driver | generic manual dispatch exists | auth/UI test | PLANNED |
| BF-051 | Critical | Dashboard | B2C has no Assign Van | generic manual dispatch exists | auth/UI test | PLANNED |
| BF-052 | Critical | Dashboard | Awaiting Dispatch queue reachable normally | dispatch state exists | route/nav + screenshot | PLANNED |
| BF-053 | High | Dashboard | Routing decision/reason explainable | trace exists | detail UI + test | PLANNED |
| BF-054 | High | Dashboard | Van detail lists B2B assigned/active/completed orders | Van management exists | runtime screenshot | PLANNED |
| BF-055 | High | Dashboard | Customer 360 timeline shows Van actor | order timeline exists | feature test | PLANNED |
| BF-056 | Critical | Driver | Driver APIs reject B2B execution after cutover | Driver supports both historically | negative API tests | PLANNED |
| BF-057 | Critical | Driver | Driver App hides/removes B2B operational paths | wholesale behavior exists historically | navigation/widget tests | PLANNED |
| BF-058 | Critical | Driver | B2C lifecycle remains intact | `DriverOrderService` is a thin adapter over shared delivery rules with legacy payload/proof persistence preserved | `DriverAssignmentLifecycleTest` + `DriverJourneyE2EAcceptanceTest` + required CI on W04 | IMPLEMENTED |
| BF-059 | Critical | Customer | B2B My Orders uses same canonical order truth | exists | E2E | PLANNED |
| BF-060 | Critical | Customer | B2B Order Detail/Tracking resolves Van actor | current tracking historically Driver-centric | widget/backend E2E | PLANNED |
| BF-061 | High | Customer | awaiting_dispatch shown truthfully | dispatch state exists backend | runtime evidence | PLANNED |
| BF-062 | High | Customer | failed/retry/delivered timeline accurate | timeline service exists | contract test | PLANNED |
| BF-063 | Critical | Warehouse | ready/pickup workflow does not skip order state | wholesale prep exists | backend E2E | PLANNED |
| BF-064 | High | Warehouse | loaded work protected from silent reroute | routing controls exist | integration test | PLANNED |
| BF-065 | Critical | Migration | Inventory all open B2B Driver/Van contradictions | not yet cutover-audited | `foodex:audit-b2b-van-cutover`; `B2BVanFulfillmentMigrationAudit`; deterministic no-write test; `FOODEX_B2B_VAN_W01_MIGRATION_DRY_RUN.md`; PR #1209 | EVIDENCED |
| BF-066 | Critical | Migration | Active B2B Driver assignments ended with audit | not migrated | migration test | PLANNED |
| BF-067 | Critical | Migration | Open B2B re-routed to Van or awaiting_dispatch | not migrated | production-like backfill test | PLANNED |
| BF-068 | Critical | Migration | B2C active Van contradictions cleaned | not audited | W01 inventory evidence: `b2c_active_van` + `b2c_dispatch_van` in dry-run/test; destructive cleanup intentionally deferred to W14 #1205; PR #1209 | EVIDENCED (W01 AUDIT) |
| BF-069 | High | Migration | historical Driver B2B remains readable | historical data exists | audit/read regression | PLANNED |
| BF-070 | Critical | Security | Van A cannot act on Van B order | exact read resolves through the current actor's active Van + active order ownership | `VanOrderControllerTest::test_van_order_detail_rejects_cross_van_stale_and_tampered_context`; PR #1215 / Required CI Gate #3933 on exact head `533735993cb0b5c0fc3eef0001c72dc56ff37ba5` (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-071 | Critical | Security | store/channel ID tampering fails safely | exact Van detail revalidates optional store/channel deep-link context and returns 404 on mismatch | `VanOrderControllerTest::test_van_order_detail_rejects_cross_van_stale_and_tampered_context`; PR #1215 / Required CI Gate #3933 on exact head `533735993cb0b5c0fc3eef0001c72dc56ff37ba5` (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-072 | Critical | Security | direct deep link revalidates current assignment | every detail request re-queries active `order_van_assignments`; stale/reassigned ownership returns 404 | `VanOrderControllerTest::test_van_order_detail_rejects_cross_van_stale_and_tampered_context`; PR #1215 / Required CI Gate #3933 on exact head `533735993cb0b5c0fc3eef0001c72dc56ff37ba5` (backend tests + MySQL/Redis acceptance PASS) | EVIDENCED |
| BF-073 | High | Audit | every routing/execution/reassignment event recorded | audit foundation exists | audit assertions | PLANNED |
| BF-074 | High | Inspector | routing/Van fulfillment failures actionable | inspector exists | inspector contract test | PLANNED |
| BF-075 | High | OpenAPI | Van fulfillment APIs documented | current OpenAPI incomplete for target | OpenAPI gate | PLANNED |
| BF-076 | High | Docs | ORDER/DRIVER/VAN workflows synchronized | old mixed assumptions exist | docs diff review | PLANNED |
| BF-077 | Critical | Route authority | all new screens/actions have canonical normal entry | route authority exists | route-authority tests | PLANNED |
| BF-078 | Critical | Evidence | Mobile Screenshot QA watches every changed Van UI path | harness exists | workflow trigger audit | PLANNED |
| BF-079 | Critical | Evidence | Dashboard visual QA covers new B2B/dispatch pages | harness exists | runtime screenshot artifacts | PLANNED |
| BF-080 | Critical | Release | update bundle/apps share final lineage | release contract exists | release gate | PLANNED |
| BF-081 | Critical | E2E | Customer-created B2B -> Van delivered | not proven | integrated test | PLANNED |
| BF-082 | Critical | E2E | Dashboard-created B2B -> Van delivered | not proven | integrated test | PLANNED |
| BF-083 | Critical | E2E | Van-created B2B -> Van delivered | partial components exist | integrated test | PLANNED |
| BF-084 | Critical | E2E | B2B routing exception -> manual Van -> delivered | not proven | integrated test | PLANNED |
| BF-085 | Critical | E2E | Van failed -> retry -> delivered | not proven | integrated test | PLANNED |
| BF-086 | Critical | E2E | B2C -> Driver delivered non-regression | current flow exists | integrated regression | PLANNED |
| BF-087 | Critical | E2E | cutover existing open B2B order safely | not proven | migration E2E | PLANNED |

## Matrix maintenance

Every implementation PR under #1190 must:

1. reference the BF IDs it changes;
2. update Current baseline/Status only when evidence exists;
3. link the exact test/workflow/artifact in the PR/Issue;
4. never mark `EVIDENCED` from source inspection alone;
5. leave unrelated rows untouched.

The final closure PR/gate must review every Critical/High row against the final exact head.
