# VAN ↔ Dashboard Parity Contract

Mission: UIUX-V42-RECOVERY  
Van target owner: #1040  
Dashboard owners: #1035 shared/admin, #1036 Commercial, #1037 Field Operations  
Integration target: `release/1034-uiux-v42-recovery`

## Purpose

The owner-approved 19-screen Van production target is a hard input to Dashboard recovery. A Van capability may not be removed, weakened, mocked, or kept mobile-only because its Dashboard control/support surface is missing.

Each Van surface below has an explicit Dashboard counterpart or a justified no-duplicate-UI rule. The owning Dashboard lane is responsible for its own runtime evidence and later #1042 performs the integrated cross-surface verification.

## Ownership rules

- **#1035 Shared/Admin** owns application administration, access/settings/publishing, shared notifications, Customer 360 support outside Field Operations-specific controls, and exact Order operations/support outside Commercial-specific rules.
- **#1036 Commercial** owns Product Catalog commercial configuration, Order commercial rules/validation, Offers, selling units/pricing/availability, and commercial finance semantics.
- **#1037 Field Operations** owns Van registry/assignments, routes/maps/visits, operational customer/store context, and Field Operations finance/reconciliation.
- A surface can have more than one counterpart when mobile behavior crosses domains. The row names one primary owner and any required supporting owner.
- Internal IDs remain implementation details. Dashboard normal workflows use business-facing labels/lookups.
- Runtime proof remains authoritative; this file is a control-plane contract, not evidence by itself.

## 19-screen parity matrix

| # | Van surface | Dashboard counterpart | Primary owner | #1035 status / exception |
|---|---|---|---|---|
| 1 | Login | Administration Hub → Applications; Users & Permissions; Mobile Settings / app access policy | #1035 | **Implemented.** Customer/Driver/Van are first-class apps; access/settings/version/publishing administration is direct. Dashboard does not duplicate the mobile login screen itself. |
| 2 | Home Dashboard | Field Operations overview + shared Driver/Van Live Tracking | #1037 + #1035 | #1035 shared tracking counterpart implemented; operational assignment KPIs belong to #1037. |
| 3 | Routes | Field Operations → routes/visits/routing policy context | #1037 | Sibling-owned; no duplicate shared/admin surface required. |
| 4 | Route Map | Field Operations → Fleet Map / tracking | #1037 | Sibling-owned. Shared mixed Driver/Van live map is additionally covered by #1035. |
| 5 | Route Detail | Field Operations → Visits / route context | #1037 | Sibling-owned. |
| 6 | Customers | Field Operations → Customers; shared Customer 360 support | #1037 + #1035 | **Implemented in #1035** for Customer 360 support; route/visit customer context is #1037. |
| 7 | Visit Workspace | Field Operations → Visits lifecycle | #1037 | Sibling-owned. |
| 8 | Customer 360 | `/admin/customer-360` exact customer support surface | #1035 | **Implemented.** Business labels, addresses/map, finance/support context, exact related-order management and no routine raw IDs. |
| 9 | Product Catalog | Commercial Sales Control / catalog commercial rules | #1036 | Sibling-owned; no duplicate shared/admin UI. |
| 10 | Order Builder | Commercial validation + shared exact Order Operations | #1036 + #1035 | **#1035 counterpart implemented** through `/admin/operations/orders` create/exact manage path. Pricing/selling-unit rules remain #1036. |
| 11 | Order Review | Commercial validation + exact Order Operations | #1036 + #1035 | **#1035 exact-record support implemented.** Commercial approval/pricing semantics remain #1036. |
| 12 | Orders | `/admin/operations/orders` + Commercial order rules | #1035 + #1036 | **Implemented in #1035** for shared operational management, direct View and compact row actions. |
| 13 | Offers | Commercial Flash Offers / Marketing | #1036 | Sibling-owned. |
| 14 | Wallet | Field Operations → Finance; commercial custody semantics where applicable | #1037 + #1036 | Existing Dashboard finance counterpart is sibling-owned; no second shared/admin wallet screen is justified. |
| 15 | Collection | Field Operations → Finance / custody ledger; commercial validation where applicable | #1037 + #1036 | Sibling-owned authoritative reconciliation surface. |
| 16 | Receipt | Field Operations → Finance / custody ledger history | #1037 + #1036 | Sibling-owned; receipt state is inspected through authoritative finance records rather than a duplicate shared/admin page. |
| 17 | Remittance | Field Operations → Finance / remittance review (approve/reject/reconcile) | #1037 + #1036 | Sibling-owned authoritative reconciliation surface. |
| 18 | Notifications | Notifications Center + Notification Campaigns + Mobile Push Settings | #1035 | **Implemented.** Van is a first-class audience/app, user targeting uses business lookups, actions use FOODEX compact patterns, push provider supports Van. |
| 19 | Profile & Settings | Administration Hub → Van app Settings / App Versions / Publishing + Profile/Security | #1035 | **Implemented.** Dashboard administers app/runtime/push/store-review policy; secure mobile tokens/biometrics remain device/session concerns and are intentionally not exposed in Dashboard. |

## #1035 closure checklist

The following shared/admin capabilities are required before #1035 may close:

- [x] One Administration sidebar entry opens a true card-based Admin Hub.
- [x] Customer / Driver / Van are first-class Applications with direct Preview, App Version and Settings actions.
- [x] Van push-provider administration is separate from Driver.
- [x] Van Store Submission and Reviewer/Test Account administration is separate from Driver.
- [x] Van Android/iOS submission metadata exists; Van Android has an independent Store Readiness AAB validation job.
- [x] Shared Notifications Center/Campaigns support Van as audience/app and business-facing user targeting.
- [x] Customer 360 provides the shared support counterpart without routine raw IDs/JSON.
- [x] Exact Order Operations provides shared create/view/manage support outside Commercial-specific configuration.
- [x] Shared Live Tracking combines Driver + Van with distinct identity and stale/online/offline truth.
- [x] Owned admin routes use the shared FOODEX shell and business-facing action patterns.
- [x] Routine raw JSON/internal-ID workflows are removed from owned normal business paths; provider-required Firebase Service Account JSON is the explicit privileged technical exception.
- [x] AR/EN, RTL/LTR and representative responsive/runtime evidence is enforced by the Visual QA workflow.
- [x] Field Operations and Commercial capabilities are explicitly routed to #1037/#1036 rather than duplicated in #1035.

## Explicit justified exceptions

1. **Mobile Login UI** is not duplicated in Dashboard. Dashboard owns access policy, users/permissions, app versions/settings and publishing readiness.
2. **Device biometric / Remember Me state and access tokens** remain mobile secure-session concerns. Dashboard must not render or store them as ordinary admin fields.
3. **Route/visit/geography/Van-registry controls** belong to #1037 and are not duplicated in shared/admin pages.
4. **Pricing/selling units/offers/commercial validation** belong to #1036 and are not duplicated in shared/admin pages.
5. **Wallet/Collection/Receipt/Remittance operational reconciliation** already has an authoritative Field Operations Finance control plane, with commercial semantics shared with #1036. A second #1035 finance UI would create conflicting sources of truth.

## Verification

- #1035 deterministic source/tests prove Admin Hub, shared shell, three-app administration, business-facing controls and shared tracking/notification/order support.
- #1035 Visual QA must fail if its required AR/EN runtime evidence is missing or if three-app/shared-tracking runtime assertions fail.
- #1036 and #1037 close their rows independently.
- #1042 performs integrated Van ↔ Dashboard runtime verification.
- #1043 remains the final requirement-matrix convergence gate.
