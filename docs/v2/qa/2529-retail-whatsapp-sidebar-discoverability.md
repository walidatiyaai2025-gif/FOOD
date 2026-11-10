# V2 P199 — Retail WhatsApp Inbox canonical Sidebar discoverability

**Source**: FOODEX Unified RBAC/ERP/Sidebar discussion, `P199`, parent #2503. **Issue** #2529. Target only `Foodex_V2`; no promotion to main, production deployment or release.

## Business workflow and placement

- **Domain / entity**: Customer Service & WhatsApp / Inbox conversations.
- **Actors**: global Customer Service Supervisor/Agent and authorized B2C Retail Customer Support for their assigned store. Deny roleless, inactive or out-of-store actors.
- **Goal**: reach the canonical operational Inbox without manually entering the URL.
- **Placement**: **Reuse** existing Dashboard authorized `customer_service` Sidebar group, existing `whatsapp_inbox` item and `admin.whatsapp-inbox.index` route. No new Sidebar entry/screen, backend endpoint or privilege.
- **Canonical renderer**: existing `WhatsAppInboxController::index`. **Navigation authority**: `AdminNavigation::whatsAppInboxItem()`, plus `AdminSidebarManager::apply()` canonical already-authorized presentation.
- **Guard**: `RuntimePolicy::allowsAgentInbox()` lifecycle/capability, `whatsapp.inbox.view` effective permission in `OperationalTenantScope`, and existing `management.dashboard`, `whatsapp.capability:agent_inbox`, per-action/record guards.
- **Scope**: B2B global actors retain B2B context; store-scoped `RETAIL_CUSTOMER_SUPPORT` sees only their Retail store; foreign-store routes remain 403/404.
- **Navigation type**: SIDEBAR, the existing domain group. AR/RTL and EN/LTR use existing `admin.nav_groups.customer_service` and `whatsapp.inbox_title` translations, existing responsive off-canvas Dashboard sidebar with keyboard focus and native links. No new styling or ARIA interaction.
- **Fallback**: when Inbox disabled/revoked, the item and empty group are absent, and the protected direct route rejects it. Do not render a dead/unauthorized link.

## Defect and smallest correction

Previously the `customer_service` group gated `whatsAppInboxItem($user)` on `canAccessPlatformErp($user)`, which requires an active **global** role. Yet `RETAIL_CUSTOMER_SUPPORT` with an active store assignment already has legitimate, scoped Inbox HTTP access under the `whatsapp.inbox.view` guard. That cosmetic *global ERP visibility* predicate concealed the approved route for Retail users.

Remove only that unrelated outer predicate; keep `whatsAppInboxItem` unchanged as the exact runtime/permission/store/route gate. Existing canonical navigation and store authorization remain server-authoritative. No new role, permission, API, WhatsApp transport, schema or seed is added.

## Verification

- `backend/tests/Feature/V2RetailWhatsAppInboxSidebarDiscoverabilityTest.php`: store-scoped Retail user finds a **single** authorized Inbox item and uses it on real HTTP GET in AR/EN, while B2B and foreign-store routes deny; global Supervisor still sees one item; disabled lifecycle or roleless actor cannot see/use Inbox.
- Existing `V2WhatsAppInboxSidebarMigrationTest` (T356R6) and `V2WhatsAppServiceRoleHttpBoundaryTest` (T087R01) cover translation/group placement and read/reply/assignment direct server authorization/forbidden writes.
- Exact PR head must pass Repository Policy, V2 Isolation, localized frontend/backend validation, complete Laravel tests, MySQL/Redis fresh install/upgrade/recovery and `required-ci-gate`. Only merge after all green; release claims prohibited.
- No new HTML/CSS/JS, so shared Dashboard mobile web and accessibility behavior is reused; independent manual viewport/keyboard/screen-reader/staging acceptance is deferred to final #2503/#2750 by owner override, not falsely marked as completed here.

## Cross-surface impact

| Surface | Change | Impact |
| --- | --- | --- |
| Laravel Dashboard | Correct authorized Sidebar discoverability, unchanged canonical Inbox business workflow | AFFECTED, tested |
| Backend API | No `/api/v1`, payload or guard modification | NOT AFFECTED |
| Customer Flutter | No auth, WhatsApp data/API contract, deep link or push change | NOT AFFECTED |
| Driver Flutter | No assignment, status, GPS or dispatch contract change | NOT AFFECTED |
| Van Flutter | No B2B delivery, routing, visit, consignment or offline contract change | NOT AFFECTED |

B2B = VAN ONLY; B2C = DRIVER ONLY. Financial maker/checker, tenant boundaries, audit, live data and storage remain unchanged. No production, main or release mutation.
