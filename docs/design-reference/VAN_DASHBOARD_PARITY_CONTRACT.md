# FOODEX Van ↔ Dashboard Capability Parity Contract

Status: OWNER APPROVED  
Parent mission: #1034  
Van execution: #1040  
Dashboard owners: #1035, #1036, #1037  
Runtime gate: #1042

## Rule

The approved Van application is not allowed to introduce an operational capability that the FOODEX Dashboard cannot administer, configure, monitor, reconcile, audit, or support where that business capability requires back-office control.

When a gap is found between the approved Van target and Dashboard capability, the gap is fixed in the Dashboard lane that owns the business domain. The Van UI must not be weakened, removed, or replaced with fake/local-only behavior to work around a missing Dashboard surface.

This is capability parity, not a requirement to clone the mobile visual layout inside Dashboard. Dashboard exposes the appropriate desktop/admin control plane over the same authoritative business data and rules.

## Recovery ownership

- **#1035 Shared/Admin** owns Van application administration/access/settings/publishing, shared notification administration, Customer 360 support, shared exact-order support, and Wallet/Collection/Receipt/Remittance visibility/audit/support outside Commercial rule ownership.
- **#1036 Commercial** owns product/selling-unit/pricing/availability rules, order commercial validation, Offers, and commercial finance semantics.
- **#1037 Field Operations** owns Van registry/assignments, routes/maps/visits/geography and the operational finance mutation surface. Its finance actions continue to use the same authoritative ledger; they do not replace #1035 shared support visibility.
- **#1040 Van App** consumes the authoritative backend/runtime contracts and may not create a separate mobile-only source of truth.

## Parity matrix

| Van target surface | Required Dashboard counterpart / control plane | Owning recovery lane | #1035 closure evidence |
| --- | --- | --- | --- |
| Login | Van application enablement, users, roles, permissions, secure access policy | #1035 | Administration Hub + Security + per-app administration implemented. Mobile login UI itself is intentionally not duplicated. |
| Home Dashboard | Van operational summary, assignment/status visibility, actionable monitoring | #1035 / #1037 | Shared Driver+Van Live Tracking implemented by #1035; route/assignment operations remain #1037. |
| Routes | Route administration, assignment, scheduling, searchable route records | #1037 | Sibling-owned operational control plane; no duplicate shared/admin route editor. |
| Route Map | Map/geography/territory/route visibility and editing where authorized | #1037 | Sibling-owned; #1035 additionally provides shared mixed Driver+Van live status. |
| Route Detail | Route stops, assignments, customer/store/order linkage and status | #1037 | Sibling-owned. |
| Customers | Customer/store administration and searchable authoritative records | #1035 / #1037 | Customer 360 shared support implemented by #1035; route/visit context remains #1037. |
| Visit Workspace | Visit planning, monitoring, result/status visibility and related records | #1037 | Sibling-owned. |
| Customer 360 | Back-office customer/store context needed to support Van operations | #1035 / #1037 | /admin/customer-360 implemented with identity, addresses/map, finance, orders and invoices using business-facing labels. |
| Product Catalog | Product/selling-unit availability, pricing and commercial configuration | #1036 | Commercial sibling-owned; no duplicate #1035 master-data editor. |
| Order Builder | Authoritative commercial rules, selling units, pricing, availability and validation controls | #1036 | Commercial builder is #1036; #1035 provides shared create/exact-order support after authoritative validation. |
| Order Review | Order/commercial validation visibility and support controls | #1036 | Commercial review semantics are #1036; #1035 provides exact-record operational support. |
| Orders | Orders grid, exact-record view/manage flow, status and audit visibility | #1035 / #1036 | /admin/operations/orders provides direct View/Create/Manage, assignment/status and delivery evidence; commercial rule ownership remains #1036. |
| Offers | Flash Offer create/edit/preview/analytics and targeting controls | #1036 | Commercial sibling-owned. |
| Wallet | Van wallet/custody balance visibility, controls, reconciliation support | #1035 / #1036 | /admin/van-finance-support provides shared read-only wallet/custody support from the authoritative ledger. Operational mutations remain canonical in Field Operations; commercial semantics remain #1036. |
| Collection | Collection policy/status visibility and operational reconciliation | #1035 / #1036 | /admin/van-finance-support?ops_tab=collections exposes business-facing collection/receipt support from the authoritative ledger; commercial policy remains #1036. |
| Receipt | Receipt lookup/view/audit/support capability | #1035 / #1036 | Collection support exposes authoritative payment/receipt references without internal IDs; commercial meaning remains #1036. |
| Remittance | Remittance visibility, status, reconciliation/approval where applicable | #1035 / #1036 | /admin/van-finance-support?ops_tab=remittances exposes status/reference/reviewer support; approve/reject/reconcile mutations remain on the canonical operational finance surface. |
| Notifications | Van notification administration/publishing/support surface | #1035 | Notification Center + Campaigns + Push Settings support Van independently with explicit Edit and compact record actions. |
| Profile & Settings | Van app settings, permissions, publishing/readiness and app administration | #1035 | Admin Hub, Security, App Versions, Mobile Settings, Push, Store Submission and Reviewer/Test Account administration support Van independently. |

## #1035 closure checklist

- [x] One Administration Sidebar entry opens a true card-based Admin Hub.
- [x] Customer / Driver / Van are first-class Applications with direct Preview, App Version and Settings actions.
- [x] Users & Permissions / access policy support Van administration.
- [x] Van push-provider administration is separate from Driver.
- [x] Van Store Submission and Reviewer/Test Account administration is separate from Driver.
- [x] Van Android/iOS submission metadata exists; Van Android has an independent Store Readiness AAB validation job.
- [x] Shared Notifications Center/Campaigns support Van as an independent audience/app with business-facing targeting and compact record actions.
- [x] Customer 360 provides shared Van customer support without routine raw IDs/JSON.
- [x] Exact Order Operations provides shared create/view/manage support outside Commercial-specific configuration.
- [x] Shared Live Tracking combines Driver + Van with distinct identity and stale/online/offline truth.
- [x] Shared Van Finance Support covers Wallet / Collection / Receipt / Remittance inspection from the authoritative ledger without duplicating mutation/business engines.
- [x] Owned admin routes use the shared FOODEX shell, direct record workflows and FOODEX action patterns.
- [x] Routine raw JSON/internal-ID workflows are removed from owned normal business paths; provider-required Firebase Service Account JSON is the explicit privileged technical exception.
- [x] AR/EN, RTL/LTR and representative responsive/runtime evidence is enforced by the Visual QA workflow.
- [x] Commercial and Field Operations capabilities remain on their canonical sibling-owned mutation/configuration surfaces rather than being forked into #1035.

## Explicit justified exceptions

1. **Mobile Login UI** is not duplicated in Dashboard. Dashboard owns users/permissions/access policy and application administration.
2. **Device biometric / Remember Me state and access tokens** remain secure mobile-session concerns and are not exposed as normal Dashboard fields.
3. **Routes, visits, geography and Van registry/assignment mutation** remain #1037 control-plane concerns.
4. **Pricing, selling units, offers and commercial order validation** remain #1036 concerns.
5. **Finance mutation ownership is not duplicated.** #1035 provides support/inspection for Wallet/Collection/Receipt/Remittance using the same collection/custody ledger; operational approve/reject/reconcile actions remain on the canonical Field Operations finance surface and commercial finance rules remain #1036.

## Verification

- #1035 deterministic tests cover Admin Hub, shared shell, three-app administration, business-facing controls, shared tracking/notification/order support and Van Finance Support.
- #1035 Visual QA must fail when required AR/EN runtime evidence, responsive action evidence, shared-shell assertions, three-app parity, mixed Driver/Van state, structured Van settings or Van Finance Support evidence is missing.
- #1036 and #1037 close their owned commercial/field-operation rows independently.
- #1042 performs integrated Van ↔ Dashboard runtime verification against this OWNER APPROVED contract.
- #1043 remains the final requirement-matrix convergence gate.
