# V2 #2723 / T356R6 — WhatsApp Inbox Sidebar migration

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, T356R6; parent #2503.
**Isolation:** issue-scoped branch `feat/2723-v2-whatsapp-inbox-sidebar-domain` → PR to `Foodex_V2` only; no `main`, tag, release or production changes.

## Placement and authority

- Domain: Customer Service & WhatsApp; entity: shared WhatsApp inbox/conversations.
- Actors: already-authorized platform and customer-service staff. Roles, scoped grants and permissions remain unchanged.
- Goal/workflow: reach the existing high-frequency operational conversation workbench.
- Decision: **Reuse**, not New Screen. Navigation type: **SIDEBAR**, under `customer_service`; existing canonical route `admin.whatsapp-inbox.index`; existing `whatsapp_inbox` key.
- Permission: `whatsapp.inbox.view`; runtime gate: `RuntimePolicy::allowsAgentInbox()`; controller authorization: `OperationalTenantScope` with per-store `whatsapp.conversations.view`, and direct HTTP authorization.
- No new route, API, role, entitlement, Store ID, company, data visibility, message sending, audit event, or privilege bypass. Existing operations group no longer owns this item.
- AR `خدمة العملاء وواتساب → صندوق الواتساب`; EN `Customer Service & WhatsApp → WhatsApp Inbox`. Existing responsive Sidebar, keyboard/focus and RTL/LTR markup are reused.

## Data preservation and compatibility

The existing `whatsapp_inbox` registry key is stable; administrator-managed layout/history, role visibility, and store permission tables are not deleted or rewritten. Existing saved custom group placement continues to be honored by `AdminSidebarManager`, so no destructive migration is attempted. The default uncustomized placement is now Customer Service & WhatsApp. The authority of server-side route checks is not affected by user-provided group placement.

## Cross-app impact matrix

| Surface | Impact | Evidence / parity |
| --- | --- | --- |
| Dashboard | **Affected** — menu group, label, same guarded route | `V2WhatsAppInboxSidebarMigrationTest` covers one link, AR/EN RTL/LTR, disabled gate 404 and unauthorized 403; full UI interaction proof still required on isolated staging |
| Backend/API | **No API contract change** — canonical web route and permission remain intact | Existing `WhatsAppAgentInboxTest` covers per-store conversations, assignment, module readiness and forbidden paths |
| Customer App | **Not affected** — no Flutter code/API response or notification schema changes | No application source changed |
| Driver App | **Not affected** — no driver API/dispatch contract changes | No application source changed |
| Van App | **Not affected** — no van API/fulfilment contract changes | No application source changed |

**CI acceptance:** run focused Feature test, full Laravel/Pint/PHPStan/security, AR/EN parity, Repository Policy, FOODEX V2 Isolation, and Required CI on the final exact PR head. Independently verify responsive Sidebar navigation in AR RTL/EN LTR on isolated staging before marking issue fully accepted. Do not claim production or visual acceptance from CI-only results.
