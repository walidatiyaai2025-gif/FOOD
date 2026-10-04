# FOODEX Operational Completion Handoff Plan

Status: **Authoritative execution map**  
Parent umbrella: #828  
Coordination issue: #829  
Repository policy: `AGENTS.md`  
Preview authority: `docs/architecture/APP_PREVIEW_ARCHITECTURE.md`

## Mission

Complete the next FOODEX operational wave across Dashboard, Laravel APIs, Customer App, Driver App and App Review/Preview without destabilizing `main`.

The mission is designed for parallel AUTO-HANDOFF execution. GitHub state is authoritative; no important execution state may exist only in chat.

## Owner-approved outcomes

1. **Add Store** becomes a prominent `+ Add Store` action that opens a gated popup wizard. The user cannot skip an invalid step. No partial Store is persisted before final confirmation.
2. **Catalog ZIP import** supports Products, Categories, Brands and their images from one ZIP. Dashboard provides a downloadable sample ZIP that users can populate and upload.
3. **Out of Stock** is server-authoritative. When available quantity is zero or less, the product is visibly unavailable, dimmed, and all purchase interactions are disabled in the Customer App. Dashboard shows the state clearly. API/cart/checkout enforcement prevents bypass.
4. **Order Management** is split into tabs by authoritative order status. Delivered orders leave active driver/order queues but remain searchable in Delivered/History.
5. **New Order** is a large `+ New Order` action on Order Management and runs as a popup wizard through final creation.
6. **Notification Campaigns** configured in Dashboard can appear as Customer App launch popups according to active window, audience, store/channel and frequency rules.
7. **Driver Order Details** contains all warehouse pickup and delivery data needed by the driver, including complete line items and address/map action.
8. **Central Lookups** appears under Operations and manages payment operation types, payment methods, pricing tiers, order statuses and failed-delivery reasons as tabs. Logic uses stable codes/IDs, never translated labels.
9. **Finance & Invoices** supports From/To/customer filtering and exports exactly the filtered result to PDF and Excel.
10. **Admin Login** defaults to `/admin/b2b/dashboard` after normal successful authentication.
11. **App Review/Preview** displays current authoritative application data for the selected persona/store/channel. Fake fixtures, stale snapshots or fallback content must never be presented as live current data.
12. **Driver Home** adds a Failed Delivery / `تعذر التوصيل` card/list.
13. **Driver Home cards update live** from current server state without manual refresh or app restart.

## Canonical catalog sample ZIP

The downloadable sample must use a deterministic, documented shape:

```text
foodex-catalog-sample.zip
├── catalog.xlsx
└── images/
    ├── products/
    ├── categories/
    └── brands/
```

`catalog.xlsx` contains:

- `Products`
- `Categories`
- `Brands`

Image references are filenames/relative paths, not local machine paths. Each row uses stable identifiers/codes suitable for idempotent matching.

The import workflow is:

```text
Download sample
  -> populate workbook + image folders
  -> upload ZIP
  -> structural/security validation
  -> row/image validation
  -> preview counts + errors
  -> confirm import
  -> transactional/safe-batch write
  -> final success/error report
```

ZIP path traversal, unsupported files, oversized payloads and invalid images must be rejected safely.

## Atomic queue

| Lane | Issue | Stable branch target | Depends on | Primary output |
|---|---:|---|---|---|
| A | #830 | `feat/830-store-create-wizard` | none | Add Store gated popup wizard |
| B | #831 | `feat/831-catalog-zip-import` | none | Sample ZIP + validated catalog/image import |
| C | #832 | `feat/832-operations-lookups` | none | Central Operations Lookups tabs/contracts |
| D | #833 | `feat/833-customer-notification-campaign-popup` | none | Customer launch popup driven by campaign config |
| E | #834 | `feat/834-finance-filter-export` | none | Finance filters + matching PDF/Excel export |
| F | #835 | `fix/835-admin-login-b2b-dashboard` | none | Default login route correction |
| G | #836 | `feat/836-authoritative-out-of-stock` | #831 | Stock authority + disabled/dimmed unavailable product |
| H | #837 | `feat/837-orders-status-tabs-lifecycle` | #832 | Status tabs + lifecycle + delivered queue removal |
| I | #838 | `feat/838-dashboard-new-order-wizard` | #832, #837 | New Order popup wizard |
| J | #839 | `feat/839-driver-ops-live-cards` | #832, #837 | Driver full details + failed-delivery card + live cards |
| K | #840 | `fix/840-app-review-live-data-parity` | #831, #833, #836, #837, #839 | Preview current-data parity |
| L | #841 | `test/841-operational-completion-e2e` | #830-#840 | Integrated E2E/release gate |

## Dependency graph

```text
#830 Store Wizard ------------------------------\
                                                 \
#831 Catalog ZIP -> #836 Out of Stock -----------+----> #840 App Review parity --\
                                                  \                             |
#832 Lookups -> #837 Order Lifecycle -> #838 New Order                          |
               \                       \                                        |
                \-> #839 Driver Ops -----+---------------------------------------+--> #841 E2E
                                                                                 |
#833 Notification Popup ---------------------------------------------------------+
#834 Finance --------------------------------------------------------------------+
#835 Login Redirect -------------------------------------------------------------+
```

## Worker count and scheduling

**Recommended pool: 6 active workers. Do not exceed 7 for this mission.**

Six is the preferred concurrency because the first six Issues are scope-disjoint enough to run together without forcing multiple workers into the same shared product/order/runtime files.

### Wave 1 — start these six workers together

- Worker 1 -> #830 Store Wizard
- Worker 2 -> #831 Catalog ZIP Import
- Worker 3 -> #832 Central Lookups
- Worker 4 -> #833 Notification Campaign Popup
- Worker 5 -> #834 Finance Filter/Export
- Worker 6 -> #835 Login Redirect

All six are initial `worker:ready` tasks.

### Wave 2 — automatic drain after prerequisites merge

As workers finish, they do **not** stop. Following `AGENTS.md` continuous queue drain:

- after #831 merges -> claim #836;
- after #832 merges -> claim #837;
- after #832 + #837 merge -> claim #838 and #839 in parallel;
- workers that finish early and have no dependency-unblocked task inspect the managed queue rather than creating duplicate branches.

Do not begin #836 before #831 because both can touch catalog/product contracts.  
Do not begin #837/#838/#839 before the required lookup/order contract ownership is merged.

### Wave 3 — parity

Start #840 only when its prerequisites are merged. Its purpose is not to manufacture preview-only data. It must reconcile Preview with the real Customer/Driver runtime and current authoritative API data.

### Wave 4 — integration

#841 is the final integrated gate. Run it only after #830-#840 are merged into `main`.

If #841 discovers a product defect, reopen/continue the owning Issue/branch or create a narrowly scoped defect only when no valid existing Issue owns that defect. Do not hide product defects inside E2E test code.

## App Review / Preview live-data invariant

The existing Preview architecture is authoritative:

- Laravel/business APIs remain the source of truth.
- Prefer existing `/api/v1` application contracts.
- Customer and Driver Preview reuse the real/shared application runtime boundary.
- Store/channel/persona authorization is server-authoritative.
- Draft vs Published is explicit.
- Network/API/auth failures produce visible error/stale states.
- Fake data must never make Preview look healthy.

For the same selected persona/store/channel, Published Preview and the real app must resolve the same current:

- stores and banners/configuration;
- categories, brands, products and images;
- pricing and stock state;
- campaign eligibility;
- Customer order state;
- Driver assignment/status-card counts;
- Failed Delivery state;
- current order lifecycle.

Preview must show a loaded/updated timestamp and expose stale/disconnected state when invalidation/live refresh fails.

## Driver Home live-card contract

Driver Home card data is read from authoritative server state and must refresh without a manual page refresh.

Required behavior:

- refresh immediately after local driver status actions;
- update automatically while the app is foregrounded;
- stop/slow background refresh according to lifecycle/battery rules;
- resume cleanly on app foreground;
- no duplicate timers/subscriptions;
- no stale count remaining after successful delivery or failed-delivery transition.

Use the existing approved real-time/invalidation mechanism if already available. If not, bounded lifecycle-aware polling is acceptable as the first implementation. Do not introduce duplicate business logic in Flutter.

The Failed Delivery card:

- is scoped to the authenticated driver and allowed channel/store context;
- shows current failed-delivery count;
- opens the corresponding failed-delivery list;
- uses the central failed-delivery reason lookup contract.

## Ownership fences

### #830
Own Store create UX/wizard and only the Store API validation changes required by the wizard. Do not redesign unrelated Store management.

### #831
Own ZIP parser/importer/sample generation/catalog import UX. Coordinate product persistence contract changes so #836 can rebase onto the merged model.

### #832
Own central lookup persistence/API/admin UI and stable code semantics. This is the authority for order statuses and failed-delivery reason identifiers.

### #833
Own notification campaign eligibility/app-open presentation. Do not duplicate Dashboard campaign management.

### #834
Own Finance/Invoice filtering/export. Export must use exactly the filtered query result and existing authorization scope.

### #835
Own post-login default route only. Preserve authorized intended-return behavior.

### #836
Own stock availability contract and Customer/Dashboard behavior after #831 merges. Server-side purchase enforcement is mandatory.

### #837
Own order lifecycle/status tabs/active-vs-history semantics. Preserve immutable store/channel provenance.

### #838
Own Dashboard New Order wizard presentation/orchestration. Consume #832/#837 status contracts; do not fork them.

### #839
Own Driver order-detail presentation, Failed Delivery card/list and live Home-card refresh. Consume #832/#837 lifecycle/reason contracts.

### #840
Own Preview parity/refresh/stale-state repair only. Do not build a parallel business API or fake presentation dataset.

### #841
Own integrated test/evidence gate. Product behavior remains owned by the responsible implementation Issue.

## Mandatory handoff state

Every active worker follows `AGENTS.md` and keeps repository-visible state current.

Use the repository's machine-readable block:

```text
<!-- foodex-worker-state:v1 -->
STATE: WORKING
OWNER: worker-name-or-role
BRANCH: feat/123-stable-branch-name
PR: #456
HEAD: abcdef123456
HEARTBEAT: 2026-10-04T05:00:00Z
BLOCKER: none
NEXT_ACTION: exact next repository action
```

When pausing/handoff, record:

- Issue;
- branch;
- PR;
- latest HEAD;
- completed work;
- remaining work;
- CI state and exact red job/test;
- next concrete action;
- genuine external blocker if any.

A replacement worker continues the **same** branch/PR.

## Definition of Done

Umbrella #828 is complete only when all of the following are true:

1. #830-#840 are merged and closed or left only with explicitly documented external-only evidence that does not invalidate product completion.
2. #841 passes against integrated `main`.
3. Required CI is green on the final integrated SHA.
4. Add Store wizard cannot skip invalid steps and never leaves a partial Store.
5. Sample ZIP download/import works end-to-end for Products/Categories/Brands/images and invalid ZIP/input is reported safely.
6. Zero-stock product is clearly Out of Stock and cannot be purchased through UI or API bypass.
7. Order tabs/counts/lifecycle are authoritative; Delivered disappears from active Driver queues and remains in history.
8. New Order popup creates an order end-to-end with server-side price/stock validation.
9. Notification campaign popup obeys configured eligibility/frequency and does not leak across scope.
10. Driver sees complete warehouse pickup/delivery details.
11. Driver Failed Delivery card/list is correct and all Driver Home cards update without manual refresh.
12. Central Lookups use stable codes/IDs and translated labels do not drive business rules.
13. Finance filtered rows and PDF/Excel exports match.
14. Normal admin login opens `/admin/b2b/dashboard`.
15. App Review/Preview shows current authoritative Customer/Driver data and visibly reports stale/error state rather than substituting fake live data.
16. B2B/B2C/store isolation and permissions pass.
17. AR/EN and RTL/LTR pass for affected surfaces.
18. No Critical/High defect remains in this mission.
19. If Dashboard/Customer/Driver release artifacts are produced, all are built from the **same final main SHA**.

## Mission command

To drain this umbrella using the repository's persistent handoff model:

```text
FOOD #828 AUTO-HANDOFF
```

That command means: reconstruct current GitHub state, preserve active valid ownership, immediately take over red/handoff-ready work, start only dependency-unblocked non-conflicting atomic Issues, continue through CI/merge, and keep draining until #828 satisfies its completion rule or a genuine external gate is reached.
