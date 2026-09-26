# FOODEX Product Completion Plan

Status: **Authoritative execution plan after Foundation**
Owner: FOODEX Coordinator
Scope: Laravel Management Dashboard + Customer Flutter App + Driver Flutter App
Primary references:
- `docs/design-reference/INDEX.md`
- `docs/business-rules/CORE_RULES.md`
- `docs/architecture/SYSTEM_ARCHITECTURE.md`
- `docs/architecture/ROLE_ARCHITECTURE.md`
- `docs/architecture/ADMIN_SHELL.md`
- `docs/api/openapi.yaml`

## 1. Why this plan exists

Closing the Foundation backlog does **not** mean FOODEX is a complete product. Foundation work establishes runtime roots, security boundaries, CI, authentication, store scoping, navigation shells, installer/updater foundations and contracts. Product completion requires all approved user journeys to work end-to-end through the real API, database, authorization, UI and tests.

The design reference currently contains **46 screens**:
- 13 B2B Customer Flutter screens.
- 12 B2C Customer Flutter screens.
- 10 B2B Super Admin Laravel web screens.
- 11 B2C Admin Laravel web screens.

Those screenshots are reference-only until their corresponding implementation issues are merged.

## 2. Product definition of done

FOODEX is a complete usable product only when all of the following are true:

1. One Laravel backend remains the single source of truth.
2. One role-based Management Dashboard serves B2B and B2C administration.
3. One Customer Flutter binary serves B2C Guest, B2C Customer and approved B2B Customer.
4. One Driver Flutter binary serves B2C_DRIVER and B2B_DRIVER with strict channel separation.
5. All 46 approved design-reference screens are implemented as responsive RTL-first UI and connected to real backend behavior.
6. B2C guest browsing works without login; checkout requires authentication.
7. B2B has no public self-registration; authorized dashboard users create/approve B2B accounts.
8. Store-dependent data is server-side store scoped.
9. Pricing, inventory, checkout, order transitions, driver assignment, reporting and permissions are backend authoritative.
10. Critical mutations are authorized and auditable.
11. OpenAPI matches the implemented API.
12. Required backend, Flutter and repository CI gates pass.
13. End-to-end acceptance journeys pass on clean production-like data.
14. Installer/update paths can deploy the release safely.
15. No design-reference row remains `Reference only — not implemented`.

## 3. Mandatory worker protocol

All workers must follow these rules:

- One Task = One Owner = One Branch.
- Work only from an open atomic Issue.
- Use the exact branch name written inside the Issue.
- Before each push, re-check Owner, Lease Start and Lease Expiry.
- Never direct-push to `main`.
- Implement only the Issue scope and acceptance criteria.
- Treat `docs/business-rules`, `docs/architecture`, `docs/design-reference` and `docs/api/openapi.yaml` as authoritative.
- UI-only work is never complete if the feature requires backend behavior.
- Open a PR to `main`, wait for every required check including `required-ci-gate`, fix failures on the same branch, resolve review conversations, perform QA, then Squash Merge.
- Delete the branch and close the Issue only after merge.
- After merge, refresh Issues, PRs, branches, CI and `main`; then promote the next dependency-unblocked task from `status:backlog` to `status:ready`.
- Never take an Issue with another valid lease.
- If a listed task does not yet have an Issue, the Coordinator creates one atomically from this plan; workers must not combine multiple queue items in one Issue.

## 4. Execution strategy

Implementation is organized as **vertical product slices**. Backend/API capability is completed first for a slice, then Dashboard/Flutter consumers are wired to it. This avoids mock-only UI and minimizes rework.

A queue item may start only when all of its declared dependencies are closed and merged.

### Wave A — Core commerce backend

#### PC-01 — Catalog, categories, products and offers completion
Branch pattern: `feat/<issue>-catalog-storefront-api`

Scope:
- Complete store/category/product/offer query behavior required by B2C and B2B.
- Filtering, pagination, active-state rules and store scoping.
- Complete product detail contract.
- Verify OpenAPI and policy coverage.

Feeds design screens: 4, 10, 11, 16-20, 28, 30, 38, 42, 44, 45.

Dependencies: Foundation, store scoping.

#### PC-02 — Cart domain completion
Branch pattern: `feat/<issue>-cart-domain`

Scope:
- Guest cart identity and authenticated cart behavior.
- Add/update/remove items.
- Merge/ownership rules at authentication.
- Server-side price and availability recalculation.
- B2C/B2B channel enforcement.

Feeds design screens: 11, 12, 21.

Dependencies: PC-01.

#### PC-03 — Checkout, addresses and payment orchestration
Branch pattern: `feat/<issue>-checkout-payment`

Scope:
- Authenticated checkout.
- Address validation/selection.
- Server-side totals.
- Idempotency.
- Payment method abstraction/configuration without embedding business rules in Flutter.
- Order creation and failure handling.

Feeds design screens: 22, 23.

Dependencies: PC-02.

#### PC-04 — Orders and customer tracking
Branch pattern: `feat/<issue>-orders-tracking`

Scope:
- Customer order listing/detail.
- Authorized status history/tracking.
- B2C/B2B separation.
- Valid order state transitions and audit trail.

Feeds design screens: 7, 8, 24, 31, 40.

Dependencies: PC-03.

#### PC-05 — Customer profile, favorites and addresses
Branch pattern: `feat/<issue>-customer-profile`

Scope:
- Profile.
- Favorites.
- Saved addresses.
- Customer-owned resource authorization.

Feeds design screens: 13, 25, 41.

Dependencies: Auth foundation, PC-01.

### Wave B — Wholesale domain

#### PC-06 — B2B account lifecycle and approval
Branch pattern: `feat/<issue>-b2b-accounts`

Scope:
- Dashboard-created B2B accounts only.
- Approval/activation lifecycle.
- Account authorization.
- No public self-registration.

Feeds design screens: 1, 29, 35.

Dependencies: Auth/roles foundation.

#### PC-07 — B2B pricing and purchasing rules
Branch pattern: `feat/<issue>-b2b-pricing`

Scope:
- Account-aware price resolution.
- Pricing/approval administration.
- Wholesale cart/order constraints.
- Backend-owned calculations.

Feeds design screens: 4, 10-12, 30, 33.

Dependencies: PC-01, PC-02, PC-06.

#### PC-08 — B2B invoices and account statement
Branch pattern: `feat/<issue>-b2b-finance`

Scope:
- Invoice listing/detail.
- Account statement and transaction history.
- Authorization and financial auditability.

Feeds design screens: 5, 6, 9.

Dependencies: PC-04, PC-06.

#### PC-09 — B2B dashboard and purchase reports
Branch pattern: `feat/<issue>-b2b-reporting`

Scope:
- Dashboard KPIs.
- Purchase charts.
- Top products.
- API-backed reporting only.

Feeds design screens: 2, 3, 4, 27, 34.

Dependencies: PC-04, PC-07, PC-08.

### Wave C — Operations, inventory and delivery

#### PC-10 — Inventory administration
Branch pattern: `feat/<issue>-inventory-admin`

Scope:
- Inventory views and mutations.
- Store/warehouse scope.
- Stock validation consumed by cart/checkout.
- Permission checks and audit.

Feeds design screens: 30, 39.

Dependencies: PC-01.

#### PC-11 — Driver and delivery assignment lifecycle
Branch pattern: `feat/<issue>-driver-delivery-domain`

Scope:
- Driver management.
- Assignment creation/list/detail/status lifecycle.
- Exact B2C_DRIVER/B2B_DRIVER separation.
- Authorization and audit.
- Extend OpenAPI only where required by approved domain behavior.

Feeds design screens: 32, 43 and Driver App product journey.

Dependencies: PC-04, roles foundation.

#### PC-12 — Platform reports and dashboard aggregates
Branch pattern: `feat/<issue>-admin-reporting`

Scope:
- B2C and platform-level aggregates.
- Store-scoped reporting for B2C admins.
- Super Admin cross-platform visibility.
- Stable report contracts for web dashboard.

Feeds design screens: 27, 34, 37, 46.

Dependencies: PC-04, PC-10, PC-11.

### Wave D — Management Dashboard implementation

The Dashboard is one Laravel application; do not create separate admin projects.

#### PC-13 — B2C Admin product screens
Branch pattern: `feat/<issue>-b2c-admin-dashboard`

Implement design-reference screens **36-46**:
- Login.
- Dashboard.
- Products.
- Inventory.
- Orders.
- Customers.
- Promotions.
- Drivers/delivery.
- Storefront preview.
- Content/banners.
- Reports/settings.

Requirements:
- Arabic RTL primary; English LTR.
- Real API/domain behavior.
- Role permissions.
- Mandatory assigned-store scope.

Dependencies: PC-01, PC-04, PC-05, PC-10, PC-11, PC-12.

#### PC-14 — B2B Super Admin product screens
Branch pattern: `feat/<issue>-b2b-admin-dashboard`

Implement design-reference screens **26-35**:
- Login.
- Dashboard.
- Stores/wholesale branches.
- B2B clients.
- Products/inventory.
- Orders.
- Drivers/delivery.
- Pricing/approvals.
- Reports.
- Settings/permissions.

Requirements:
- Real backend actions.
- B2B channel authorization.
- Audit critical changes.

Dependencies: PC-06, PC-07, PC-08, PC-09, PC-10, PC-11, PC-12.

### Wave E — Customer Flutter product

One Customer App binary owns B2C and approved B2B presentation; backend owns business rules.

#### PC-15 — B2C Customer Flutter journey
Branch pattern: `feat/<issue>-b2c-customer-journey`

Implement design-reference screens **14-25**:
- Splash.
- Guest-or-login entry.
- Store selection.
- Store home.
- Offers.
- Product list/filter.
- Product detail.
- Cart.
- Login at checkout.
- Address/payment.
- Order tracking.
- Profile/favorites.

Dependencies: PC-01 through PC-05.

#### PC-16 — B2B Customer Flutter journey
Branch pattern: `feat/<issue>-b2b-customer-journey`

Implement design-reference screens **1-13**:
- Login.
- Dashboard.
- Purchase reports.
- Top products.
- Invoices.
- Account statement.
- Orders.
- Order detail/tracking.
- Invoice detail.
- Products.
- Product detail/cart.
- Checkout.
- Profile/settings.

Dependencies: PC-06 through PC-09 plus PC-04.

### Wave F — Driver Flutter product

#### PC-17 — Driver App end-to-end journey
Branch pattern: `feat/<issue>-driver-app-journey`

Scope:
- Authentication.
- Role/channel-aware navigation.
- Assignment list/detail.
- Delivery status actions supported by the authoritative backend contract.
- Error/loading/offline-safe presentation states.
- B2C_DRIVER and B2B_DRIVER never cross.
- No business-rule duplication in Flutter.

Dependencies: PC-11.

### Wave G — Product hardening and release

#### PC-18 — Cross-surface E2E acceptance
Branch pattern: `test/<issue>-product-e2e`

Required journeys:
1. B2C guest -> browse -> cart -> login -> checkout -> track order.
2. B2C Admin -> manage scoped store catalog/inventory/order/customer/content.
3. Authorized admin -> create/approve B2B customer -> B2B login.
4. B2B customer -> account pricing -> cart/order -> invoice/statement/reporting.
5. Admin -> assign B2C delivery -> B2C driver receives only B2C assignment.
6. Admin -> assign B2B shipment -> B2B driver receives only B2B assignment.
7. Permission-denial and store-isolation tests.
8. Arabic RTL and English LTR smoke coverage.

Dependencies: PC-13, PC-14, PC-15, PC-16, PC-17.

#### PC-19 — Production release readiness
Branch pattern: `release/<issue>-product-readiness`

Scope:
- Clean install.
- Upgrade from previous supported version.
- DB migrations and rollback safety.
- Seed/bootstrap minimum production data.
- Logging/observability verification.
- Mobile app version policy.
- Release configuration and environment documentation.
- No unresolved P0/P1 product defects.
- Final OpenAPI consistency.
- Release checklist and evidence.

Dependencies: PC-18 and App Version Policy issue #22.

## 5. Scheduler dependency graph

```text
Foundation + #22
   |
   +--> PC-01 --> PC-02 --> PC-03 --> PC-04
   |      |         |                  |
   |      +--> PC-10                   +--> PC-11
   |      |
   |      +--> PC-05
   |
   +--> PC-06 --> PC-07 --> PC-08 --> PC-09

PC-01/04/05/10/11/12 --> PC-13 B2C Admin
PC-06/07/08/09/10/11/12 --> PC-14 B2B Admin
PC-01..05 --> PC-15 B2C Customer
PC-04 + PC-06..09 --> PC-16 B2B Customer
PC-11 --> PC-17 Driver
PC-13..17 --> PC-18 E2E
PC-18 + #22 --> PC-19 Release
```

Where dependencies permit, independent Issues may run in parallel, but a single Issue must never have multiple active owners/branches.

## 6. Design-reference coverage matrix

| Design rows | Surface | Queue |
|---|---|---|
| 1-13 | B2B Customer Flutter | PC-06 to PC-09, PC-16 |
| 14-25 | B2C Customer Flutter | PC-01 to PC-05, PC-15 |
| 26-35 | B2B Super Admin Web | PC-06 to PC-12, PC-14 |
| 36-46 | B2C Admin Web | PC-01, PC-04, PC-05, PC-10 to PC-13 |
| Driver product journey | Driver Flutter | PC-11, PC-17 |

Every row must be changed from reference-only status only when its real implementation is merged and QA evidence exists.

## 7. Issue creation contract

For each PC item, the Coordinator creates one atomic GitHub Issue containing:

- Objective.
- Scope.
- Out of scope.
- Affected applications/modules.
- Dependencies by Issue number.
- Exact design-reference rows.
- Exact API paths/contracts.
- DB changes if any.
- Permission/store-scoping rules.
- Required tests.
- Exact branch name.
- Owner.
- Lease start/expiry.
- Acceptance criteria.
- Definition of done.

A PC item may be split into smaller atomic Issues if implementation risk is too large for one branch. Splitting is allowed; silently combining separate PC items is not.

## 8. Readiness policy

The product queue is now instantiated as canonical GitHub Issues:

| Queue item | Issue |
|---|---:|
| PC-01 | #51 |
| PC-02 | #52 |
| PC-03 | #53 |
| PC-04 | #54 |
| PC-05 | #55 |
| PC-06 | #56 |
| PC-07 | #57 |
| PC-08 | #58 |
| PC-09 | #59 |
| PC-10 | #60 |
| PC-11 | #61 |
| PC-12 | #62 |
| PC-13 | #63 |
| PC-14 | #64 |
| PC-15 | #65 |
| PC-16 | #66 |
| PC-17 | #67 |
| PC-18 | #68 |
| PC-19 | #69 |

Initial scheduling:
- PC-01 #51 and PC-06 #56 are the only dependency-unblocked starting items from this queue.
- PC-05 #55 remains `status:backlog` until PC-01 #51 is merged.
- PC-10 #60 remains `status:backlog` until PC-01 #51 is merged.
- All other dependency-blocked PC items remain `status:backlog`.
- Scheduler promotes blocked items automatically when every listed dependency is closed and merged.
- Project-board status is updated when available; repository labels remain the scheduling fallback.
- Issue #22 (App version policy) is an independent existing work item and is also a release dependency for PC-19 #69.

## 9. Completion measurement

Do not report Foundation Issue closure percentage as Product completion.

Product completion is measured from the product execution queue:
- **Implementation completion** = closed/merged PC implementation Issues divided by total instantiated PC implementation Issues.
- **Screen completion** = implemented-and-QA-passed design-reference screens / 46.
- **Release readiness** reaches 100% only after PC-18 and PC-19 pass.

If a PC item is split, its child Issues replace that item in the denominator so the metric reflects actual executable work.

## 10. Stop conditions

A worker must stop and mark the Issue blocked rather than guess when:
- Required business behavior is not defined by the approved sources.
- A payment/provider-specific decision is required but not documented.
- A schema/API change would contradict OpenAPI or core rules.
- Another valid lease owns the same task.
- A dependency is not merged.

All other normal implementation/test/review failures are fixed by the worker on the same Issue/branch without requesting manual intervention.


## 11. Wave H — PH-05 Premium FOODEX visual system and dashboard parity

User-approved on 2026-09-25. This wave becomes authoritative for new FOODEX presentation work while preserving all backend/business rules from prior waves.

- Epic #111 — premium dashboard reference, brand system, demo data and cross-surface rollout.
- #112 PH-05A — centralized FOODEX brand tokens + design contract. Branch: `feat/112-foodex-brand-design-contract`.
- #113 PH-05B — pixel-match premium Management Dashboard. Branch: `feat/113-premium-dashboard-pixel-match`. Depends on #112.
- #114 PH-05C — deterministic visual-QA/demo seed dataset. Branch: `feat/114-dashboard-demo-seed`. Depends on #112.
- #115 PH-05D — branding/dashboard impact rollout across B2C and Customer/Driver mobile. Branch: `feat/115-brand-cross-surface-rollout`. Depends on #112 and #113.
- #116 PH-05E — visual regression + cross-surface acceptance. Branch: `test/116-foodex-premium-visual-e2e`. Depends on #113, #114 and #115.

Mandatory PH-05 rule: workers use `docs/architecture/FOODEX_BRAND_SYSTEM.md` and `docs/design-reference/PH05_PREMIUM_DASHBOARD.md` as visual authorities. Any shared change must include explicit B2C Admin, Customer App and Driver App impact assessment. No worker may introduce a separate palette, dashboard-only business state or mock-only production metric.

## 12. Wave I — Usable-product acceptance and release closure (2026-09-25)

This section supersedes historical **initial scheduling** and any claim that closed
foundation/product issues alone prove production readiness. Existing business scope
and architecture remain unchanged.

### Verified baseline

- Inspected main: `d290520d14a2f962770e1f3b52d4152bf93ef7fd`.
- PH-05A/B/C/D are merged. PR #120 head `fc946317a9a5de6127bab2c9777c46045323c4d1`
  passed backend, both Flutter Android/iOS validations and required-ci-gate.
- CI currently runs on PRs: no main push workflow runs/check-runs were returned.
  Associate CI evidence with the actual tested PR head; do not invent a main run.
- #116 is reserved by the existing QA owner; consult its latest lease/heartbeat,
  not this snapshot, before scheduling. #111 remains open until its acceptance passes.
- INDEX.md still marks 46 screens reference-only. This is an evidence gap, not proof
  that all 46 implementations are absent. Audit each row before changing its status.
- Production release checklist is unchecked; release-readiness.sh checks structure,
  not a successful deployment. Flutter CI currently builds debug Android/iOS targets.
- TranslationCatalog currently omits existing reports/mobile_settings language groups.

### Atomic execution queue

| Task | Exact branch | Dependency / claim status at creation | Acceptance |
|---|---|---|---|
| #121 Plan reconciliation | `docs/121-usable-product-plan` | This documentation task | Current evidence, explicit queue and worker gates |
| #122 Translation Center coverage | `fix/122-translation-catalog-coverage` | Ready; claim live lease first | Reports/mobile settings editable in AR/EN; override/reset/audit/API regression |
| #123 Screen coverage evidence | `test/123-screen-coverage-evidence` | Backlog until #116 merged | Per-screen implementation + route + role/store + visual/functional AR/EN evidence |
| #124 Deployment acceptance | `test/124-deployment-acceptance` | Ready; independent test scope | Real PostgreSQL/Redis clean install, upgrade, backup/restore and failure recovery evidence |
| #125 Mobile release artifacts | `release/125-mobile-release-artifacts` | Ready; coordinate CI/native files | Branded release builds, endpoint configuration, version/commit/checksums and explicit signing blockers |

Issues are the live source of ownership/status. All newly discovered gaps become small,
non-duplicate issues tied to an approved PC/PH/RC requirement; do not silently expand a
reserved worker's scope. A queued audit is not equivalent to a passing release gate.

### Worker scheduling and coordination

1. Refresh all open/closed issues, PRs, branches, required checks and main before each
   cycle. Check manual, Codex and GPTDesktop workers through live owner/lease/heartbeat records.
2. One Task = One Owner = One Branch. Use the exact issue branch. Record owner, UTC or
   offset-bearing lease start and expiry (two hours), scope and touched shared files.
3. Renew only on meaningful commit, PR update, progress finding or test evidence. Recheck
   owner and expiry immediately before every push. Never duplicate an active reservation.
4. Expired lease with no meaningful heartbeat: read issue criteria, commits, changed files,
   PR discussions, tests and blockers; post TAKEOVER and continue the same branch.
5. Red main/required gate takes priority: pause new feature scheduling, create/reuse a P0
   repair task. Fix failed PRs on the same branch; update from main and resolve conflicts
   inside that task's scope. Do not bypass required checks.
6. If no Ready item exists, promote dependency-unblocked approved backlog work. GitHub
   Project status should mirror labels when available; labels are the scheduling fallback.
7. Every shared change records impact on B2C Admin, B2B Admin, Customer and Driver apps.
   Coordinate central routing/auth/OpenAPI/migrations/CI/design tokens before editing.
8. PR to main only -> all required checks -> resolved reviews -> functional/visual QA ->
   squash merge -> branch deletion -> issue Done -> refresh next eligible task.

### Release exit gates and evidence format

For each gate record tested commit, environment, exact command/journey, result and an
artifact or CI URL. Missing/unexecuted evidence stays **Not verified**, never PASS.

- All 46 reference rows have implementation and visual/functional AR RTL + EN LTR evidence.
- PH-05 brand uses canonical FOODEX tokens/assets across all surfaces; deterministic demo
  data reproduces the approved dashboard without entering production bootstrap.
- Complete B2C guest/cart/auth/checkout/tracking, B2B account/pricing/order/finance and
  role-separated Driver delivery journeys pass against the real backend.
- Buttons/actions, empty/error/loading states, validation, permissions, store/channel
  isolation, notifications, editable translation coverage and audit are verified.
- Deployment/upgrade/rollback, queues, logs and production configuration pass with
  PostgreSQL/Redis. Existing checklist items require individual evidence.
- Both mobile release builds pass. Unsigned validation is recorded separately from signed,
  installable/store-ready artifacts; absent identifiers/credentials stay explicit blockers.
- No open P0/P1 defects, OpenAPI matches routes, and every applicable CI gate is green.

Report implementation, verified screen coverage and release readiness separately.
Do not calculate product percentage from issue closure alone. Close this release wave
only after the evidence above and any resulting defects are resolved.

## 10. Production activation additions — approved 2026-09-26

The first production installation is approved at `https://foodex.50sols.com`.
Its non-secret deployment identity is maintained in
`docs/release/PRODUCTION_CONFIGURATION.md` after PR #212 merges. PostgreSQL database
and username are both `solscool_foodex`; passwords and signing/provider secrets remain
external and must never be committed.

### RC-06 — Firebase mobile push integration — #210

Branch: `feat/210-mobile-firebase-push`

- One Firebase production project for the two approved Flutter binaries.
- Firebase project display name: **FOODEX Production**.
- Customer Firebase app nickname: **FOODEX Customer**.
- Driver Firebase app nickname: **FOODEX Driver**.
- Suggested Firebase project ID: `foodex-50sols-prod`, subject to Firebase availability.
- Final Android application IDs and iOS bundle IDs remain explicit required inputs and
  must exactly match Firebase registrations; workers must not invent production IDs.
- Customer and Driver must register/refresh/revoke device tokens against the existing
  backend push-device contract.
- Foreground/background delivery, tap/deep-link handling, AR/EN presentation and
  B2B/B2C audience isolation are mandatory.
- Firebase service-account keys, APNs keys and mobile signing secrets remain external.

Dependencies: existing Notifications Center + Mobile Push Settings; final native IDs
must be approved before Firebase app registration can be completed.

Definition of Done: real-device Customer + Driver push acceptance passes on Android/iOS,
required CI is green, and no provider secret is stored in git.

### PC-20 — Live Management Dashboard notifications — #211

Branch: `feat/211-dashboard-live-notifications`

- Add authenticated real-time notification delivery to the Management Dashboard.
- Header bell shows a live unread count and a Premium FOODEX notification feed.
- New authorized events appear without a full page refresh.
- Support mark-read / mark-all-read and navigation to the Notifications Center.
- Cover approved operational events including new orders and relevant
  order/payment/delivery/administrative notification changes.
- Server-side user/role/store/channel scoping remains authoritative.
- Arabic RTL and English LTR, reconnect/fallback, duplicate suppression, empty/error/
  offline states and responsive web behavior are required.

Dependencies: existing Notifications Center (#93) and its per-user read state.

Definition of Done: live delivery and unread state pass authorization/store-isolation
tests, reconnect does not duplicate events, visual QA passes, and required CI is green.

### Scheduling

#210 and #211 are separate tasks with separate owners/branches. They may run in parallel
only when their active files do not conflict. #210 remains blocked on final native
application/bundle IDs and Firebase configuration inputs. #211 is implementation-ready.
Neither task may bypass required CI or direct-push to `main`.

