# V2 P188 — Wholesale Customers navigation authority

**Source:** Unified RBAC / ERP / Sidebar discussion, legacy matrix `P188` (actual source paragraph `P177`); **issue:** #2518; **parent:** #2503; **integration:** `Foodex_V2` only.

## Route and placement

- **Domain:** Customer & Sales → Wholesale Customers. **Entity:** canonical B2B customer/account and Platform Customer identity.
- **Role / user goal:** authorized Wholesale operator with effective `b2b.accounts.view` opens real B2B client records; a B2C store admin must not gain access through a menu link.
- **Placement decision:** **Reuse** the existing `accounts → customer_360` Dashboard Sidebar entry for the customer-identity journey; the existing Wholesale workspace also provides the dedicated B2B Clients module for account operations. Do not introduce a second customer table, misleading independent visibility switch, or incomplete ERP page. Navigation type: `SIDEBAR` for Customer 360, `PAGE_TAB / WORKSPACE` for Wholesale clients.
- **Primary navigable entry:** `AdminNavigation::groupsFor` / `customer_360`, label key `admin.customer_360` (Arabic/English), route `admin.customer-360.index`, GET `/admin/customer-360`; parent `accounts`. The existing root Dashboard and channel workspace provide back paths.
- **Wholesale operations route:** `admin.b2b.module` with `module=clients`, GET `/admin/b2b/clients`, `B2bWorkspaceController::show` and `navContext=b2b_clients`, existing localized key `admin.b2b_workspace.modules.clients`. This is a separate existing B2B account management surface, not an ERP placeholder.
- **Authorization:** `b2b.accounts.view` under `B2bWorkspaceController::MODULE_PERMISSIONS`, validated at HTTP by `TenantContextResolver::wholesale` and `authorizeModule`; `Customer360Controller::access` selects `b2b` or `b2c` mode with `OperationalTenantScope`. The Sidebar `customer_360` is a projection, not a grant; global/store permission overrides must not authorize a URL by presentation alone.
- **Readiness/entitlement:** these are existing core customer management routes, not a new ERP module that may be marked enabled without workflow evidence. Permission, session, active role and tenant/channel boundaries remain enforced. No new entitlement or module enablement.
- **Localized UX:** shared Dashboard shell, current responsive Sidebar and B2B workspace, AR RTL / EN LTR, existing empty-state and read-only list. No new form, CSS, component, field or menu hierarchy. Customer/account mutations continue to need their separate precise permissions.

## Acceptance and negative paths

`backend/tests/Feature/V2WholesaleCustomersSidebarAuthorityTest.php` covers canonical `accounts/customer_360` discovery and single entry, real AR/EN HTTP 200, correct B2B mode and account module, Retail-only / roleless direct B2B HTTP 403, explicit `b2b.accounts.view` denial and Retail-only customer confidentiality. Direct non-authorized navigation must fail on the server rather than relying on Sidebar hiding. Customer 360's B2B-only query projection must not expose a Retail-only customer.

The original source requests expired-user, other-store and disabled-module negative cases. These are covered by existing middleware/tenant and core-module contracts; this Issue's regression specifically probes the B2B-versus-Retail boundary and does not invent an ERP enable toggle for core customer routes. Real-browser responsive keyboard/accessibility and full production persona UAT are tracked for final V2 acceptance, not represented as performed by this source-only PR.

## Customer / Driver / Van impact

- **Backend:** additive Laravel HTTP / authorization / tenant regression. No runtime code, API, database, permission or migration change.
- **Dashboard:** reuses current Customer 360 and B2B Clients screens; test and source documentation only.
- **Customer App:** **NOT AFFECTED** — no Customer Flutter code, public API schema, authentication, catalog, pricing or ordering change.
- **Driver App:** **NOT AFFECTED** — no Retail dispatch, driver status or GPS semantics; B2C remains DRIVER ONLY.
- **Van App:** **NOT AFFECTED** — no wholesale route, assignment, visits or collection changes; B2B remains VAN ONLY.

## Delivery gate

One Issue #2518 / branch `test/2518-v2-wholesale-customers-sidebar-authority` / one PR exclusively against `Foodex_V2`. Validate exact-head Repository Policy, FOODEX V2 Isolation, backend PHP tests/lint/static analysis, AR/EN localization, Required CI and real MySQL/Redis acceptance before merge. Do not modify `main`, production, tags, releases or APKs. **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** only after code and exact-head checks are complete, under latest owner instruction #2503.
