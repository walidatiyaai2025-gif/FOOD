# V2 #3501 — Van visit completed_with_order channel provenance

Parent Van MOB-V02 #2738, Visits P268 #2598, final isolated V2 #2503. Integration **Foodex_V2 only**. No `main`, production, tags, releases, APK publication or deployment.

## Defect and atomic source acceptance
`VanVisitLifecycleService::transition()` verifies matching order customer FK and optional store before setting `status=completed_with_order`, but does not check `orders.channel` against the visit's `customer_type`. A malformed imported order with `channel=b2c` and a retained matching `b2b_customer_id` could be linked to a Wholesale Van visit. This is a source-level inconsistent-ledger risk, not proof of a live incident.

The same canonical DB transaction and row-lock is preserved. The existing validation branch now rejects any absent order, mismatched customer, store **or channel** with field `order_id` before touching `order_id`, status, timestamps or no-order reason. EN and AR catalogs share one `van_visit_guard.order_customer_store_channel_mismatch` key. No change to other state transitions, idempotent repeats, reason lookup, order status, schema, or API status codes.

## Tests and evidence
- New `backend/tests/Feature/V2VanVisitOrderChannelGuardTest.php`: four `RefreshDatabase` methods exercise contradictory Retail-channel order with matching Wholesale customer/store FK in **both Arabic and English** and prove no visit status/time/order changes; matched B2B order success; unrelated-store denial; unrelated-customer denial.
- Existing `VanVisitLifecycleServiceTest` and API `VanVisitControllerTest` remain in full Laravel CI.
- Required exact-final-head checks: Repository Policy, FOODEX V2 Isolation, FOODEX Required CI (PHP syntax, Pint, PHPStan, Composer security, OpenAPI, Laravel full tests, localization quality, real MySQL/Redis Fresh Install/Upgrade/Recovery, terminal `required-ci-gate`). No claim of test execution until results are confirmed in GitHub Actions.
- Independently scoped from original CLOSED/UNMERGED #3207 / #2738 B2B Van API/idempotency PR; no restart/replacement of that owner-closed PR. This is a separate domain-level order-channel provenance consistency guard.

## Per-surface and permissions matrix
| Surface | Actual impact |
| --- | --- |
| Backend | `VanVisitLifecycleService` only: order channel/customer/store consistency within existing transaction |
| Dashboard | Existing authorized Field Operations visit transition consumes same `order_id` ValidationException field; no view, navigation or authorization grant change |
| API | Same `order_id` validation failure structure and status code; existing localized message, no new route/DTO |
| Customer | No Customer API or Flutter surface change. B2B fulfilment remains VAN ONLY |
| Driver | No Driver API or Flutter change. B2C fulfilment remains DRIVER ONLY |
| Van | Existing visit completion cannot bind mismatched B2C order; no Van Flutter code or response schema change |

No new UI routes or forms; the existing authorized Visit action is reused. No database rows are deleted, no migration or permission changes introduced. Cross-channel partner sale, physical custody, trip closeout and final Van device acceptance remain broader independent work under #2738/#2602/#2739/#2503.

## Merge/closure contract
One issue, one worker, one original branch `fix/3501-v2-van-visit-order-channel`, one PR base Foodex_V2. Only after all exact-final-head required checks are terminal SUCCESS and source review passes, SHA-guarded merge into Foodex_V2 then close **atomic** #3501 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with merged PR, tested source SHA, integration SHA and workflow links. Master #2503 stays open until final staging/human UAT; no production authorization.
