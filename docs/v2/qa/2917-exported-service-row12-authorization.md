# V2 #2917 — historical exported customer-service triple-role authorization (T5 / data row 12)

**Source:** Native `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Table 5, data row 12. Parent #2503. Integration is **Foodex_V2 only**. The original source includes confidential personal account details, which must remain out of the repository, issue comments, CI logs and PR.

## Archived snapshot is not current authority

The historical export recorded three concurrent global customer-service-related roles: `CUSTOMER_SUPPORT`, `CUSTOMER_SERVICE_AGENT`, `CUSTOMER_SERVICE_SUPERVISOR`, and a reported *43 distinct inherited permissions* at the time of export. This count is a **historical observation**, NOT a specification for `config/permissions` and NOT evidence of the current account's role pivots, direct overrides, status, scoped store/tenant grants or app identity. Never broaden role permissions merely to match the exported count.

The exact account identifier, name, email, username, password, contact details and full role/permission fingerprint have not been copied into automated tests or public GitHub. No live identity was accessed or modified.

## Authority and application matrix

| Boundary / persona | Canonical control and observed test contract | Application impact |
| --- | --- | --- |
| Wholesale Customer Support / Agent / Supervisor combined | `User::inheritedPermissionCodes` deduplicates the currently seeded role union. `Role` and `User::effectivePermissionCodes` are checked using synthetic assignments. | Dashboard tested; no new route or copy |
| Service entry and Supervisor identity | Existing `admin.erp.customer-service`, `AdminNavigation::usesCustomerServiceDashboardProfile`, Wholesale role/channel tests | Dashboard AR/EN, RTL/LTR HTTP and Sidebar checks |
| Privilege boundary | `roles.manage`, `users.delete`, Security Center, Finance Post/Approve and service team identity administration never follow merely from overlapping service roles | Dashboard negative authorization |
| Tenant/channel | Explicit Retail store and `OperationalTenantScope` B2C restrictions; no cross-store view or Retail inventory navigation | Dashboard B2B authorized, Retail B2C rejected |
| Override deny and activation | `user_permission_overrides` global `deny` for WhatsApp supervision remains effective even with all three roles, while unrelated service work remains possible. Inactive users lose effective rights but retain auditable role history | Dashboard negative lifecycle regression |
| Driver App | A Customer Service Dashboard actor with a synthetic `app:driver` token cannot access Driver wallet; no B2C Driver persona or assignment inferred | No mobile API or Flutter change; existing denial regression |
| Van App | A Customer Service Dashboard actor with either app token cannot access Van visits absent a Van role/assignment | No mobile API or Flutter change; existing denial regression |
| Customer App | No change to public Customer identity, pricing, order or store contracts. Dashboard RBAC membership **does not** prove or disprove legitimate Customer account entitlement | No mobile API or Flutter change; no Customer login rejection assumed |

## Nonduplication and delivery boundary

This issue owns **only two NEW files**:

- `backend/tests/Feature/V2ExportedServiceTripleRoleRow12AuditTest.php`, synthetic triple-role edge cases and AR/EN HTTP/Sidebar checks
- `docs/v2/qa/2917-exported-service-row12-authorization.md`, sanitized source-to-control mapping and acceptance gate

Previously merged #2770 covers **single Supervisor** role, #2686 covers Supervisor workspace and team controls, while concurrent #2769 PR #3315 owns **two-role Support+Agent** remediation. None of those files, current RBAC services, migrations, API contracts, UI or mobile code are modified here. Reuse existing permission/route/Sidebar logic; there is no new page or navigation placement, so the existing accessibility and AR/EN contracts remain in effect.

## Unmet current-account audit / acceptance gate

Before marking #2917 complete, a separately authorized **least-privilege read-only** private reconciliation must determine:

1. Which current account (if any) corresponds to this historic export record, without posting its identifiers.
2. Current account type, `is_active`, global and store role pivots, inherited/overridden/effective permissions, module and store entitlements, and tenant/channel/Sidebar projection.
3. Whether any support/agent/supervisor role combination grants excessive privilege, cross-store reach, unauthorized Customer/Driver/Van admission, or customer-service maker/checker bypass.
4. A least-privilege role consolidation proposal, approval, independent audit evidence, approved effective change, negative test proof and recovery plan **only if a real defect is found**.

Never infer the account must be deleted or deactivated, and never perform a production account change from the archived export. Manual UAT is deferred on parent #2503, but private source-account attestation and remediation are **not** waived.

**CI:** verify Laravel-focused + full backend tests, PHP syntax/Pint/PHPStan/security, Localization AR/EN, required repository/isolation gates, and MySQL/Redis installation/upgrade/recovery on the **exact final PR SHA**. Merge any bounded technical regression only into `Foodex_V2` after green CI. Leave #2917 OPEN until source-account audit and actual remediation, if required, are evidenced. No main, release, tags or production.
