# FOODEX Van ↔ Dashboard Capability Parity Contract

Status: OWNER APPROVED  
Parent mission: #1034  
Van execution: #1040  
Dashboard owners: #1035, #1036, #1037  
Runtime gate: #1042

## Rule

The approved Van application is not allowed to introduce an operational capability that the FOODEX Dashboard cannot administer, configure, monitor, reconcile, audit, or support where that business capability requires back-office control.

When a gap is found between the approved Van target and Dashboard capability, the gap is fixed in the Dashboard lane that owns the business domain. The Van UI must not be weakened, removed, or replaced with fake/local-only behavior to work around a missing Dashboard surface.

This is capability parity, not a requirement to clone the mobile visual layout inside Dashboard. Dashboard should expose the appropriate desktop/admin control plane for the same authoritative business capability.

## Parity matrix

| Van target surface | Required Dashboard counterpart / control plane | Owning recovery lane |
| --- | --- | --- |
| Login | Van application enablement, users, roles, permissions, secure access policy | #1035 |
| Home Dashboard | Van operational summary, assignment/status visibility, actionable monitoring | #1035 / #1037 |
| Routes | Route administration, assignment, scheduling, searchable route records | #1037 |
| Route Map | Map/geography/territory/route visibility and editing where authorized | #1037 |
| Route Detail | Route stops, assignments, customer/store/order linkage and status | #1037 |
| Customers | Customer/store administration and searchable authoritative records | #1035 / #1037 |
| Visit Workspace | Visit planning, monitoring, result/status visibility and related records | #1037 |
| Customer 360 | Back-office customer/store context needed to support Van operations | #1035 / #1037 |
| Product Catalog | Product/selling-unit availability, pricing and commercial configuration | #1036 |
| Order Builder | Authoritative commercial rules, selling units, pricing, availability and validation controls | #1036 |
| Order Review | Order/commercial validation visibility and support controls | #1036 |
| Orders | Orders grid, exact-record view/manage flow, status and audit visibility | #1035 / #1036 |
| Offers | Flash Offer create/edit/preview/analytics and targeting controls | #1036 |
| Wallet | Van wallet/custody balance visibility, controls, reconciliation support | #1035 / #1036 |
| Collection | Collection policy/status visibility and operational reconciliation | #1035 / #1036 |
| Receipt | Receipt lookup/view/audit/support capability | #1035 / #1036 |
| Remittance | Remittance visibility, status, reconciliation/approval where applicable | #1035 / #1036 |
| Notifications | Van notification administration/publishing/support surface | #1035 |
| Profile & Settings | Van app settings, permissions, publishing/readiness and app administration | #1035 |

## Gap-closure rules

1. Dashboard is the control plane for authoritative configuration and back-office support.
2. If the Van target needs a data source or state that Dashboard cannot currently configure or inspect, implement the missing Dashboard capability in the correct owning lane.
3. Do not invent duplicate mobile-only master data to bypass a Dashboard gap.
4. Do not remove an approved Van screen to avoid implementing the corresponding Dashboard capability.
5. Existing backend semantics, authorization, audit and business rules remain authoritative.
6. Where no human-admin action is legitimately required, document the exception with evidence rather than creating meaningless UI.
7. AR/EN, RTL/LTR and shared FOODEX admin-shell rules still apply to all added Dashboard surfaces.

## Completion gate

The recovery mission cannot be considered visually/functionally complete while an unresolved Van ↔ Dashboard capability gap remains.

#1042 must verify the integrated runtime against this parity contract in addition to the screen-level visual contract.
