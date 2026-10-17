# Dashboard Navigation Discoverability Audit — #1989

Source lineage at claim: `c07d075c938ec59d18cb8af0f6b28064a762da77`.

## Result

The audit reconciles the existing adaptive route inventory with the current navigation authorities instead of treating “the page renders the Sidebar” as discoverability. The machine-readable authority is `docs/execution/DASHBOARD_NAVIGATION_CAPABILITY_INVENTORY.json`.

- 42 existing route families were re-audited for inbound navigation ownership.
- 49 durable capability rows are classified after adding the recently delivered Restaurant SaaS and advertising surfaces.
- No durable operator capability is currently proven `NAV_MISSING` or `NAV_WEAK`.
- Compatibility aliases are not counted as independent screens.
- Feed, lookup, export/download, asset/attachment, webhook/callback, mutation-only, preview-data, public storefront/SEO and raw site-scoped JSON service endpoints are explicit `INTERNAL_EXCEPTION` classes rather than silent omissions.
- Recent Restaurant SaaS discoverability is resolved by #1990: one domain entry, shared workspace navigation and permission/site-aware contextual links.

## Navigation hierarchy proven

The current product hierarchy is:

`Sidebar / domain entry → domain workspace or Admin/Settings hub → page tab/context action → exact record/action`.

This is intentionally not “every route in the Sidebar.” Exact-record pages, site-scoped Restaurant pages, Settings/Admin pages and operational subtabs remain owned by their natural parent surface.

## Duplicate / compatibility findings

| Route | Classification | Canonical authority | Owner |
| --- | --- | --- | --- |
| `admin.business.index` | `DUPLICATE_DESTINATION` | Authoritative B2B/B2C workspaces | #1991 |
| `admin.catalog.categories.index` | `DUPLICATE_DESTINATION` | `admin.catalog.index?tab=categories` | #1991 |
| `admin.b2b.orders.canonical` | `DUPLICATE_DESTINATION` | `admin.operations.orders.index` | #1991 |
| `admin.b2c.orders.canonical` | `DUPLICATE_DESTINATION` | `admin.operations.orders.index` | #1991 |

These are not reasons to add navigation. They are compatibility/redirect contracts that must either remain explicitly registered or be retired safely by the remediation issue.

## Representative inbound evidence

- ERP Center and its Procurement/WMS/Finance/Treasury/BI/Customer Service destinations are owned by `AdminNavigation`.
- Administration, Settings, Security, System Inspector and mobile release operations are reachable through the Administration/Settings hubs.
- System lookups are a Settings Center item and own their local tabs.
- Profile is reachable from the account menu rather than the Sidebar.
- Field Operations owns address-quality and exact-record actions inside the Field Operations workbench.
- Invoice detail is an exact-record contextual destination.
- Restaurant SaaS owns Settings, Operations, Menu, Appearance, QR and Analytics as stable workspace links; Supply, Integrations and Growth are site-context links. Reservations, Waitlist, Guest Book, KDS and Workforce remain operation subtabs.
- Raw customer-growth/loyalty/service-recovery JSON endpoints are not operator screens; operator discovery remains through the canonical Restaurant operations/customer context.

## Permission and persona evidence

`AdminNavigationAuthorizationAuditTest` exercises visible navigation for Super Admin, wholesale admin and retail store admin and fails when a visible destination returns authorization/route errors. #1990 additionally closed the concrete cross-channel defect where a B2B-only admin could see Restaurant SaaS.

The ledger records permission/entitlement ownership per capability. Permission-hidden links must disappear rather than becoming 403 navigation.

## AR/EN and responsive evidence

The current repository localization gate validates Arabic/English contracts. Dashboard runtime evidence validates the shared admin shell, and Restaurant Menu/Appearance are included in the full responsive matrix at 320/360/390/412/430/768/1024/1280+ with AR/RTL and EN/LTR.

## Handoff

#1991 should consume only the explicit duplicate/compatibility cleanup findings and any future route drift discovered after this lineage. #1992 should convert this ledger contract into the deterministic required-CI navigation coverage gate. Neither child should create a parallel screen merely to satisfy route enumeration.
