# V2 #2687 — CUSTOMER_SERVICE_AGENT (T122R7)

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, table row T122R7. Parent: #2503. Integration: Foodex_V2 ONLY. No main, release/tag or production changes.

## Existing role and placement

Reuse the canonical built-in role definition in backend/config/job_roles.php, reconciled by BuiltInJobRoleCatalog and CoreReferenceSeeder. Existing AdminNavigation groupsFor exposes only authorized customer-service destinations; no new role, route, sidebar visibility mask, or ERP module activation is added.

- Persona: CUSTOMER_SERVICE_AGENT, global role assignment, tenant business scope, operator level.
- Navigation: existing ERP Customer Service, Customer 360 and wholesale Orders workspaces. Route permission and OperationalTenantScope must agree with Sidebar visibility.
- Allowed customer/order actions: customers.view, orders.view/create/dispatch and orders.customer_service_discount in authorized wholesale context. The discount permission originates from the canonical legacy roles matrix in backend/config/permissions.php, which BuiltInJobRoleCatalog::defaultPermissions preserves ahead of the fallback job_roles catalog. Discount execution is separately constrained by its audited pre-dispatch reason/approval controls; V2 #2824 owns full discount approval/SoD acceptance. The previously approved dispatch grant does not imply order edit/manage/approve or Driver/Van registry and tracking access.
- Messaging: inbox/conversations/reply/assign/internal notes subject to the independent WhatsApp runtime capability and explicit effective permissions.
- Explicitly NOT inherited: finance/treasury approval, SoD maker/checker, user/role administration, platform management, reports/export, team management, supervisory escalation, retail-store authority.
- Customer Service Agent is not Super Admin; no global-role fallback can grant cross-channel B2C store access.
- B2B fulfillment remains VAN ONLY and B2C fulfillment remains DRIVER ONLY. No fulfillment logic or identity is modified here.

## Change and evidence

One new isolated Laravel feature test at backend/tests/Feature/V2CustomerServiceAgentRoleAuthorityTest.php verifies:
1. Built-in catalog and seeded role permission equality, AR/EN role presentation and denied elevated permissions.
2. B2B wholesale allowed scope and B2C forbidden scope for orders/customers; AR RTL and EN LTR authorized Dashboard routes and navigation; direct 403 for retail, finance reports and security workspace.
3. Global explicit user DENY on orders.view revokes HTTP access without altering role grants; deactivated actor has no authorized navigation or B2B order-create scope.

Existing related tests independently cover customer identity, order action boundaries, service journeys and supervisor roles. No shared runtime source, permission catalog, role definitions, routes, Blade templates, database schema or mobile assets are changed by this patch.

## Customer / Driver / Van impact decision

| Component | Impact |
| --- | --- |
| Backend | Tests only; existing API and security contracts are asserted, not modified |
| Dashboard | Existing AR/EN, RTL/LTR UI/navigation and direct HTTP server authorization regression checks; no UI change |
| Customer Flutter | No impact: no user identity, session, storefront, API schema, messages or Flutter source changed |
| Driver Flutter | No impact: no dispatch route, assignment, status, proof or Driver application source changed |
| Van Flutter | No impact: no wholesale Van assignment, visit, route, stock, settlement or Van application source changed |

## Acceptance and follow-up

Run PHP/Pint, Laravel tests, Security/Repository Policy, AR/EN localization, FOODEX V2 Isolation Gate, applicable MySQL/Redis installation/upgrade/recovery and exact-head FOODEX Required CI. Passing CI is not yet claimed here. Only merge after all required checks pass on the latest PR head. Real staging/device/browser validation belongs to master #2503 and gate #2750 under the owner's final-acceptance policy, with no fake claim of completed manual UAT. If tests expose a runtime defect, recheck shared-file ownership before editing canonical code, and fix on this same issue branch/PR.
