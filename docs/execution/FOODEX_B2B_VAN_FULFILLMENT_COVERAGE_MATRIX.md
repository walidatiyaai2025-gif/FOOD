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
| BF-001 | Critical | Policy | Channel determines fulfillment actor | Mixed historical Driver/Van assumptions | Backend contract tests: B2B Van-only, B2C Driver-only | PLANNED |
| BF-002 | Critical | Routing | Customer App B2B order invokes Smart Routing | Routing service exists; order-create integration incomplete | E2E Customer checkout -> OrderVanAssignment | PLANNED |
| BF-003 | Critical | Routing | Dashboard-created B2B invokes same routing | Order engine exists | E2E Dashboard order -> Van assignment | PLANNED |
| BF-004 | Critical | Routing | Van-created B2B invokes same routing | Van order capture exists | E2E visit order -> policy-selected Van | PLANNED |
| BF-005 | Critical | Routing | No Driver fallback for unresolved B2B | Manual Driver dispatch currently exists generically | awaiting_dispatch + explicit Van-only manual queue | PLANNED |
| BF-006 | Critical | Routing | B2C never auto-assigns Van | Van routing service accepts generic Order | B2C routing guard test | PLANNED |
| BF-007 | Critical | Dispatch | Manual B2B dispatch offers/accepts Van only | assignDriver + assignVan both exist | UI/API authorization tests | PLANNED |
| BF-008 | Critical | Dispatch | Manual B2C dispatch offers/accepts Driver only | mixed dispatch capability exists | UI/API authorization tests | PLANNED |
| BF-009 | High | Routing | Reassignment preserves history | OrderVanAssignment history foundation exists | reassignment + audit test | PLANNED |
| BF-010 | High | Routing | Physically loaded/out-for-delivery work not silently rerouted | policy concept exists | lock/override acceptance test | PLANNED |
| BF-011 | Critical | Backend | Van assigned-order query includes Customer/Dashboard-created orders | Van order list is visit/customer scoped | active OrderVanAssignment read-model test | PLANNED |
| BF-012 | Critical | Backend | Exact Van B2B Order Detail API | no complete assigned-delivery detail contract | authorization + payload test | PLANNED |
| BF-013 | Critical | Execution | Van acceptance | Driver behavior exists | Van transition API + test | PLANNED |
| BF-014 | Critical | Execution | Van picked-up | Driver behavior exists | Van transition API + test | PLANNED |
| BF-015 | Critical | Execution | Van out-for-delivery updates Order | Driver behavior exists | shared-rule + Van adapter test | PLANNED |
| BF-016 | Critical | Execution | Van delivered updates Order | Driver behavior exists | shared-rule + Van adapter test | PLANNED |
| BF-017 | Critical | Execution | Van failed delivery + configured reason | Driver behavior exists | failure-reason contract test | PLANNED |
| BF-018 | Critical | Execution | Van retry after failed | Order allows retry | Van retry E2E | PLANNED |
| BF-019 | Critical | Execution | Van transition idempotency | Driver has fingerprint behavior | duplicate/retry test | PLANNED |
| BF-020 | Critical | Execution | Reassigned/stale Van cannot mutate | scope foundation exists | stale-assignment rejection test | PLANNED |
| BF-021 | Critical | Proof | Delivery proof required before delivered | Driver rule exists | Van proof upload + delivered gate | PLANNED |
| BF-022 | High | Proof | Proof appears in Dashboard timeline | Driver evidence exists | B2B Van timeline test + screenshot | PLANNED |
| BF-023 | Critical | Finance | Required collection blocks Delivered | Driver rule + Van collection domain exist | Van E2E collection gate | PLANNED |
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
| BF-058 | Critical | Driver | B2C lifecycle remains intact | mature Driver flow exists | full regression gate | PLANNED |
| BF-059 | Critical | Customer | B2B My Orders uses same canonical order truth | exists | E2E | PLANNED |
| BF-060 | Critical | Customer | B2B Order Detail/Tracking resolves Van actor | current tracking historically Driver-centric | widget/backend E2E | PLANNED |
| BF-061 | High | Customer | awaiting_dispatch shown truthfully | dispatch state exists backend | runtime evidence | PLANNED |
| BF-062 | High | Customer | failed/retry/delivered timeline accurate | timeline service exists | contract test | PLANNED |
| BF-063 | Critical | Warehouse | ready/pickup workflow does not skip order state | wholesale prep exists | backend E2E | PLANNED |
| BF-064 | High | Warehouse | loaded work protected from silent reroute | routing controls exist | integration test | PLANNED |
| BF-065 | Critical | Migration | Inventory all open B2B Driver/Van contradictions | not yet cutover-audited | dry-run report | PLANNED |
| BF-066 | Critical | Migration | Active B2B Driver assignments ended with audit | not migrated | migration test | PLANNED |
| BF-067 | Critical | Migration | Open B2B re-routed to Van or awaiting_dispatch | not migrated | production-like backfill test | PLANNED |
| BF-068 | Critical | Migration | B2C active Van contradictions cleaned | not audited | migration report/test | PLANNED |
| BF-069 | High | Migration | historical Driver B2B remains readable | historical data exists | audit/read regression | PLANNED |
| BF-070 | Critical | Security | Van A cannot act on Van B order | scope partial | authorization test | PLANNED |
| BF-071 | Critical | Security | store/channel ID tampering fails safely | platform guards exist | 403/404 tests | PLANNED |
| BF-072 | Critical | Security | direct deep link revalidates current assignment | push/deep-link exists separately | authorization test | PLANNED |
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
