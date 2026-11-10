# V2 #2528 — P198 Customer Service & WhatsApp authorized navigation group

## Source and canonical placement

- Source: FOODEX Unified RBAC ERP Sidebar Discussion, 2026-11-09, Customer & Sales / P198; parent #2503 (V2 isolation and final UAT override).
- **Domain:** Customer & Sales / Customer Service & WhatsApp.
- **Entity / user goal:** Agent Inbox and the associated authorized customer-conversation workbench. Users need to open assigned conversation queues, not a second customer-service screen.
- **Actor:** SUPER_ADMIN with real authentication, CUSTOMER_SERVICE global roles where granted, RETAIL_CUSTOMER_SUPPORT with an active assigned B2C store, or a custom role with a valid operational permission scope.
- **Entry:** The existing Customer Service group → WhatsApp Inbox child, visible only when runtime policy permits agent_inbox and the actor has an effective scope. The group header is a disclosure, never an RBAC grant.
- **Placement:** Reuse existing Sidebar business group and existing admin.whatsapp-inbox.index workbench. Navigation type = SIDEBAR; parent surface = Customer Service; pattern = Operational Workbench. The route is backed by WhatsAppInboxController::index; no duplicate URL or screen.
- **Rationale:** Reuse is sufficient: role-scoped Inbox navigation is already a child in AdminNavigation::groupsFor; AdminSidebarManager::apply removes empty groups and presentation-hidden children. Adding or composing a new screen would duplicate existing Customer Service routing and authorization.
- **Permissions:** Sidebar checks RuntimePolicy::allowsAgentInbox() and AdminNavigation::hasPermissionInScope for whatsapp.inbox.view; the actual HTTP route applies whatsapp.capability:agent_inbox, while the controller requires either whatsapp.inbox.view or effective whatsapp.conversations.view scope via OperationalTenantScope. The latter restricts B2C conversations and explicit store filters; an unauthorized store ID receives HTTP 404. No Sidebar or request query param grants permission.
- **Runtime readiness:** WhatsApp module must be operational (INTERNAL_TEST, or properly certified ENABLED), and explicitly enable the agent_inbox capability. DISABLED, missing or false capability hides the child and blocks direct navigation with HTTP 404. The group is omitted when it has no children.
- **Channel and store:** Retail support is limited to active, assigned B2C stores. Global support roles use only their approved effective channel scope. Cross-store store_id requests are denied. B2B/retail isolation and all underlying conversation permissions remain unchanged.
- **Presentation:** Role-visibility settings may hide links without revoking an otherwise authorized route. The absence of a Sidebar link does not independently enforce security; HTTP middleware/controller enforce it.
- **Localization and responsive:** Group label admin.nav_groups.customer_service exists in AR (خدمة العملاء وواتساب) and EN (Customer Service & WhatsApp). Existing Sidebar provides semantic disclosure with keyboard focus, RTL/LTR shared shell and responsive off-canvas navigation; Inbox has an existing responsive workbench. This test-only change adds no CSS or text.

## Acceptance and regression evidence

New backend/tests/Feature/V2CustomerServiceWhatsAppGroupAuthorityTest.php verifies real Laravel route responses and authoritative navigation:

1. Disabled runtime or disabled agent-inbox capability removes the empty group and returns HTTP 404 for direct Inbox links even to SUPER_ADMIN.
2. With operational capability, exactly one canonical Inbox child exists under the Customer Service group; real HTML is rendered in AR/RTL and EN/LTR.
3. Assigned B2C retail support sees its own Inbox, but a foreign B2C store ID is denied; disabling its only assigned store removes access and hides the group.
4. Authenticated user without permissions sees neither the group nor its HTTP Inbox.
5. Admin presentation setting hiding the only child removes the group but does not pretend to revoke server authority.

The pre-existing WhatsAppAgentInboxTest covers actual conversation scoping, agent/assignment workflows, attachment security and mobile-responsive Inbox markup. WhatsAppPolicyTest covers lifecycle and runtime fail-closed. No WhatsApp message is transmitted by these tests.

## Required surface impact matrix

| Surface | Impact and reason |
| --- | --- |
| Backend | Regression-only feature test for existing navigation, HTTP authorization, tenant scope, and runtime readiness; no production middleware, routes, models or API contracts changed |
| Dashboard | Existing group, child Inbox workbench, AR/EN/RTL navigation; no duplicate Sidebar item, CSS or renderer |
| Customer Flutter | Not affected: no API response schema, message delivery, identity, route, login, notification, or deep link contract change |
| Driver Flutter | Not affected: no Driver-specific dispatch/route/status payload or app code change |
| Van Flutter | Not affected: no Van visit, warehouse, fulfillment/dispatch, or app code change |

No additional mobile implementation Issue is required because this change is exclusively a server-side regression test and QA document. B2B=VAN ONLY and B2C=DRIVER ONLY remain untouched.

## Isolation and remaining acceptance

- One Issue #2528 → branch test/2528-v2-customer-service-whatsapp-group-authority → one PR targeting Foodex_V2 only.
- Verify the exact HEAD: Repository Policy, V2 Isolation Gate, applicable Backend Laravel/MySQL-Redis tests, localization gate, security/lint/OpenAPI, final required-ci-gate.
- After green CI only, squash merge into Foodex_V2, verify merged files and remove branch through GitHub, document SHA and checks, close leaf only if all executable requirements are proven.
- MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL (master #2503); final browser/device and exhaustive cross-V2 program audit remain separate. Neither main nor any production release/tag/deployment is authorized.
