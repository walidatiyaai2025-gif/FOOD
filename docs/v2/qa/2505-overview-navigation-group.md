# V2 #2505 — Overview Sidebar group (P175)

## Source and placement

- Source: Native DOCX P175 / `نظرة عامة`, under master #2503.
- Domain: Overview / cross-domain navigation; entity: user-authorized dashboard destinations.
- Primary goal: reach existing summary and work surfaces without leaking unavailable modules, tenants or channels.
- Pattern: **reuse** canonical Sidebar group in `AdminNavigation::groupsFor()`. No new page, route, role grant, entitlement or Sidebar widget.
- Navigation owner/type: `AdminNavigation`, `SIDEBAR`, group key `overview`, label `admin.nav_groups.overview`; current AR `نظرة عامة`, EN `Overview`.
- Entry: shared Dashboard Sidebar, with the existing off-canvas Drawer on mobile web. Parent: Dashboard shell. Desktop/tablet/phone and RTL/LTR use the same authorization-filtered group.
- Canonical destinations currently include independently checked My Work (`admin.action-center.index`), enterprise workspaces (`admin.enterprise-workspaces.index`), channel dashboards (`admin.{b2b|b2c}.module`), and role-specific Customer Service workspace (`admin.erp.customer-service`); notifications are included only for their authorized role.
- Routing/fallback: each child opens its existing canonical route. No group-heading href and no grant through appearance settings. An empty group is filtered by `AdminNavigation` and `AdminSidebarManager`, while missing routes are fail-closed.

## Authorization and data scope

| Condition | Sidebar projection | HTTP boundary |
| --- | --- | --- |
| Active B2B Admin | My Work, enterprise workspaces and eligible wholesale destination(s) | Each visible child must resolve successfully |
| Active scoped B2C Store Admin | My Work and own-store dashboard; no global workspace or wholesale dashboard | Enterprise Workspace direct HTTP may be allowed at scoped Retail permissions, but privileged system-health cards stay denied; foreign-store dashboard is forbidden |
| Inactive or no-role identity | No Overview group | My Work forbidden |
| Unsupported, missing or disabled target | No inert navigation item | Existing route, entitlement, module and permission authorities remain binding |

Note: EnterpriseWorkspaceComposer intentionally permits a Retail actor to open a **limited, store-authorized** workspace directly while AdminNavigation may withhold its platform-level Sidebar entry. The initial regression assumption that this direct route must return 403 was rejected by full CI; correcting that assumption preserves authoritative per-card permissions rather than imposing an unreviewed blanket deny. The separate V2 P180 workspaces navigation scope remains tracked by #2510; this P175 group regression does not silently redefine it.

Individual children retain their existing permission, tenant/store/channel, and module-readiness authority. The group container never constitutes a permission. Do not equate a visible route with blanket ability to mutate data; all action methods remain subject to server guards and maker/checker decisions.

## Automated evidence

`backend/tests/Feature/V2OverviewSidebarGroupContractTest.php` verifies:
1. canonical group ordering and zero-empty-group policy;
2. AR and EN localized business labels, stable child ordering, authorized HTTP reachability;
3. Retail own-store navigation and exclusion of platform/Wholesale Sidebar links, legitimate store-scoped direct Enterprise Workspace reads with privileged system-health cards filtered, and foreign-store dashboard 403;
4. inactive and roleless group suppression plus direct My Work 403.

This PR reconciles the already-existing implementation and adds a focused regression guard; no shared Sidebar source file is edited while #3029, #3039 and #3041 own overlapping areas. Required CI must verify this exact PR HEAD before any isolated merge. Manual keyboard, viewport, screen reader, operator device and final UAT remain deferred to #2503/#2750, **not claimed completed here**.

## Customer / Driver / Van impact

- **Customer:** not affected. No Flutter, public API, customer route, ordering or pricing change.
- **Driver:** not affected. No Driver Flutter/API, delivery state, authentication or dispatch change.
- **Van:** not affected. No Van Flutter/API, routing or B2B dispatch change.
- B2B=Van only and B2C=Driver only remain unchanged; this patch is Dashboard-only test/documentation.

## Security / rollback / delivery

No schema, data, secrets, production user role, configuration, or runtime code is changed. Revert the issue-scoped test/document PR if needed. Target strictly `Foodex_V2`, never `main`; no version promotion, release, tag, production installation or deployment.
