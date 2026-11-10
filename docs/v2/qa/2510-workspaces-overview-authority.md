# V2 #2510 — Workspaces within Overview (P180)

## Canonical placement and source

- Source: FOODEX Unified RBAC/ERP/Sidebar, Part 2 §4, P180 (`مساحات العمل`); parent #2503.
- Domain/goal: cross-domain enterprise task workspaces; discover authorized business work through the existing Dashboard Overview group.
- Placement: **Reuse** the existing `AdminNavigation::groupsFor` Overview item `enterprise_workspaces` and its single canonical renderer `EnterpriseWorkspaceController`. No duplicate route/screen, Sidebar section, independent permission grant or decorative link.
- Navigation type: SIDEBAR (Overview → Workspaces), then PAGE_TAB within the existing workspace; depth stays within the established Sidebar limit. Existing `admin.enterprise-workspaces.index` resolves `/admin/workspaces/{workspace?}`, and accepts only executive, finance, sales and warehouse. Default opens the first authorized workspace.
- Authority: `EnterpriseWorkspaceComposer::availableWorkspaceKeys` determines available tabs from effective permission/store scope. `compose` filters each card through permission, module entitlement and real named route existence. The controller denies empty available scope (403), disallowed valid tabs (403), and route-constrained unknown tabs (404).
- Canonical owner: `AdminNavigation` + `EnterpriseWorkspaceController` + `EnterpriseWorkspaceComposer`; no direct-URL-only capability. Sidebar Workspaces entry now derives visibility from the **same** `EnterpriseWorkspaceComposer::availableWorkspaceKeys` decision used by the HTTP controller, including legitimate per-store Retail scope. This is a navigation-only projection, **not** a new permission grant. Back navigation uses the existing Dashboard Sidebar and standard browser navigation.
- Presentation: existing `admin.enterprise-workspaces` uses responsive card grid (3/2/1 columns), AR RTL / EN LTR, keyboard-focusable page tabs and card links, active `aria-current`, and an explicit empty state. No new UI components or localization keys.

## Permission, route and state contract

| Actor/condition | Navigation/tab projection | HTTP enforcement |
| --- | --- | --- |
| Active B2B role with effective platform scope | Workspaces child under Overview; only effective workspace tabs and cards | Canonical GET succeeds for accessible tab |
| Legitimate B2C store-only actor with effective store permissions | Scoped Workspaces entry under Overview; only actual permitted workspace tabs/cards | Authorized Sales and other scoped workspaces return 200; platform-only cards such as `system_health` remain denied; no platform grants |
| Active roleless or inactive identity | No Workspaces entry | Controller/middleware denies with 403 |
| Workspace access permission removed | Tab excluded from available keys | Direct GET to former tab returns 403 |
| Card permission denied or module not entitled | Card is omitted; valid empty workspace has localized empty state | No extra privilege or permission grant from Sidebar layout |
| Unknown workspace name | No tab | Route parameter rejects with 404 |

The group is not authorization. Global/store permissions, channel/tenant constraints and module entitlement remain enforced at the underlying controller/service. Platform and Retail roles cannot be interchanged through Sidebar visibility or reordering. The Customer Service focused dashboard-profile exclusion is preserved; it must not be represented as an authorization denial.

## Automated regression

`backend/tests/Feature/V2WorkspacesOverviewAuthorityTest.php` covers four real contracts:

1. Authorized B2B actor sees the canonical Overview child, opens the real named route and each permitted workspace, with AR/EN labels and RTL/LTR and active-tab semantics.
2. Removing one workspace access permission removes its tab and denies direct GET. A card configured with a denied permission never becomes an actionable link, even if the workspace is reachable.
3. A legitimate Retail store-only actor sees the canonical Workspaces Sidebar destination and can open authorized scoped Sales workspace HTTP 200 without `platform.manage`; no platform-only `system_health` card is exposed, and a workspace whose effective access grant is removed returns 403. A roleless actor sees no link and receives HTTP 403 on both default and direct Sales URLs.
4. An unsupported workspace path cannot become a hidden fallback.

The parent #2505 tests Overview as a group; this issue tests the Workspaces interior, independent role/permission projection and server boundary. No test, runtime implementation or documentation file owned by an active worker has been changed.

## Surface impact / compatibility

- **Backend:** Canonical `EnterpriseWorkspaceComposer` is injected into the existing `AdminNavigation` so the Sidebar mirrors effective global/store-scoped HTTP Workspace eligibility. No controller authorization/API mutation, migrations, queues, storage, cache or new role/permission.
- **Dashboard:** corrects existing Overview → Workspaces link discoverability for legitimately authorized store-scoped Retail users, exercises real HTTP tabs and card filtering; no new page, route, UI theme or permission authority.
- **Customer:** not affected; no Flutter, API shape, retail ordering or auth change.
- **Driver:** not affected; no Flutter, dispatch/GPS/delivery API change; B2C DRIVER ONLY unchanged.
- **Van:** not affected; no Flutter, routing/visits/wholesale API change; B2B VAN ONLY unchanged.
- **Security/SoD:** no new grants, privilege escalation, financial write or maker/checker bypass.

## Delivery and acceptance

Target branch: `Foodex_V2` only. Require exact-HEAD Repository Policy, FOODEX V2 Isolation, AR/EN, Laravel/Pint/PHPStan/security and all relevant MySQL/Redis fresh-install/upgrade/recovery and `required-ci-gate` jobs successful before integration. Test failures are to be repaired on the same issue branch/PR; there is no bypass.

`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under the owner override in #2503: physical responsive 320–1280px, full keyboard/screen reader and real operator UAT are deferred to final comprehensive V2 program acceptance, not claimed as performed here. No `main` merge, production deployment, release or tag.
