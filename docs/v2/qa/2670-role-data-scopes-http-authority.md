# V2 #2670 — Administration data-scope authority (P340)

Source: FOODEX Unified RBAC/ERP/Sidebar (2026-11-09), Sidebar matrix key **P340**, native DOCX paragraph **P329**. Parent: #2503. PR base: **Foodex_V2** exclusively.

## Canonical workflow and user-facing placement

- Domain → entity → role: **Administration → Security Center → Enterprise Access & Delegations → Role data scopes**; Super Admin / explicitly authorized security-scope maker; a separate checker with `security.approve`.
- User goal: review authoritative enterprise role data scopes, request a scope grant/removal in a specific organization/company/legal entity/business unit/store, and apply it only after independent approval.
- Workflow: existing `/admin/security/enterprise` GET → protected `role-scopes.store` POST → pending `enterprise_security_privileged_change` → `changes.decide` by another authorized actor → persisted `enterprise_role_data_scopes` and `enterprise.security.change_applied` audit.
- Placement: **Reuse** the current enterprise security route and `security-enterprise.blade.php` data-scope form/table. It is a settings/master-data approval workbench; no second Sidebar item, data model or standalone app page.
- Desktop/tablet/mobile-web: preserve shared responsive Dashboard components, including `es-grid` and single responsive role-scope form; UI changes are intentionally **zero**. Manual viewport review (320–1280 px) is deferred to master #2503/#2750, not claimed.
- AR/RTL and EN/LTR: both are existing localized views, asserted by route tests. Existing form/accessibility labels remain.

## Authority and data isolation

- Read: authorized `security.view` actor; no roleless visitor.
- Mutation: real server Gate `security.scopes.manage`, *not* presence of the form. `EnterpriseSecurityManager::assertRoleGovernance` additionally reuses `EnterpriseSecurityAccessResolver::allows` for the requested organization/store/tenant scope and prevents delegated actors from granting permissions beyond their own effective authority.
- Workflow requires a different authorized checker; a maker cannot approve their own scope grant. The data-scope record must not exist while the request is pending; approved grants are audited.
- Unauthorized direct HTTP POST is denied even if a user manually constructs it. Delegated actor assigned to organization A cannot grant a role an organization B scope, even when they have the generic global permission.
- Channel/store: store-scoped roles do not gain authority over another store from this screen. Existing role-scope and User effective-permission tests remain the canonical tests of inheritance, cross-store separation and channel boundaries; the issue-specific test exercises the cross-organization tenant denial.
- Disabled ERP module: this Security Center governance function is an independent security administrative surface, **not** an ERP module whose enabling/disabling grants privilege. No active module is changed, revived or auto-entitled here; normal module-specific allow/deny gates remain authoritative elsewhere.
- No tenant, role assignment, permission or finance mutation is performed in the Issue diff. HTTP test uses SQLite temporary seeded data only and verifies the real application workflow.

## Issue-specific regression

New `backend/tests/Feature/V2RoleDataScopeSidebarHttpAuthorityTest.php` includes:
1. Existing role-scope workbench accessible to Super Admin in English/LTR and Arabic/RTL.
2. Real authorized HTTP request enters **PENDING**; no scope change before checker; maker approval 403; independent checker applies and audits the scope grant.
3. Delegated read-only viewer does not see scope mutation form and gets direct POST 403; roleless actor gets GET/POST 403; no database workflow/grant.
4. Scoped security operator cannot request a foreign organization scope and no workflow is written on forbidden scope; own organization request is accepted into the pending workflow.

Regression maintained: `EnterpriseSecurityAdministrationTest` already covers core maker/checker idempotence, organization isolation, approval limits and delegation expiration. The new test adds the missing actual HTTP and UI route acceptance for native source P329.

## Customer / Driver / Van impact matrix

- **Customer:** no API/auth payload, Flutter screen, checkout, prices, tenant or role mapping change — no mobile implementation needed.
- **Driver:** no App route, API, collections, dispatch or role assignment runtime change — B2C DRIVER ONLY unchanged.
- **Van:** no App route, API, visits, territory or role assignment runtime change — B2B VAN ONLY unchanged.

## Delivery constraints

One Issue → `test/2670-v2-role-data-scope-http-authority` → one PR targeting **Foodex_V2 only**. No direct Foodex_V2 commit, no `main`, production, tags, setup/update publication, or real user data. Required: exact PR HEAD Repository Policy, V2 Isolation and final Required CI including PHP/Pint/PHPStan/security, AR/EN, full Laravel backend, MySQL/Redis fresh install/upgrade/recovery. No merge before all succeed; release branch and Issue lease only after verified completion. **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL.**
