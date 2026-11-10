# V2 P200 — Customer conversations within the canonical WhatsApp Inbox

Issue #2530, parent #2503. Source: FOODEX Unified RBAC/ERP/Sidebar Discussion 2026-11-09, `P200`. **Foodex_V2 integration only; never main or production.**

## Product route, placement and authority

- **Domain:** Customer Service & WhatsApp; **entity:** WhatsApp conversation / PlatformCustomer.
- **Actor:** authenticated active global Customer Service Agent/Supervisor or store-scoped Retail Customer Support. A retail employee sees only authorized, assigned-store conversations, never wholesale or another Retail store's data.
- **User goal:** search/list conversations, select a permitted conversation and inspect its existing chronological thread and customer context; find actionable empty/error states without knowing internal IDs.
- **Placement:** **Reuse** existing `customer_service` Sidebar group and `whatsapp_inbox` entry, canonical `GET admin.whatsapp-inbox.index` at `/admin/whatsapp/inbox`. P199/#2529 already corrected Retail-store authorized discoverability. No second menu link, duplicate route, standalone Conversations page, permission engine, or UI primitive.
- **Backend:** `WhatsAppInboxController::index` with `conversationQuery`, `ConversationService::conversationForActor`, `OperationalTenantScope::allowedStoreIds`, `whatsapp.inbox.view` / `whatsapp.conversations.view` permissions. Read and selected record are scoped server-side; `store_id` foreign filter returns 404, foreign selected conversation denied, roleless denied.
- **Readiness/entitlement:** `whatsapp.capability:agent_inbox` and `WhatsAppModuleState` must permit Inbox; disabled module returns 404 and navigation is absent. The existing Inbox controls obey distinct per-action permissions for reply, assignment and status (see #2821); read-only existence of the conversation list grants no write.
- **Localized UX:** existing `admin.whatsapp-inbox` Blade uses canonical FOODEX dashboard/off-canvas Sidebar and already-translated Arabic RTL / English LTR labels, search/status/store filters, localized no-conversations/no-messages states, keyboard-safe native links/forms, responsive list/thread layout; no markup or visual style changed in this QA scope.
- **Fallback/back path:** existing Inbox list/search/filters with a localized no-conversations state; disabled/revoked actor uses protected forbidden/not-found server response. No raw key/ID input is introduced to business users.

## Delivered independent regression

`backend/tests/Feature/V2CustomerConversationsInboxScopeTest.php` executes live Laravel HTTP requests using synthetic, store-separated `PlatformCustomer` conversation fixtures:

1. Retail store A actor can search and select its conversation in both AR/EN and is denied/leak-proof against store B (search, direct selection, store filter).
2. Resolved-status filter with no matching conversations renders the localized empty-state and does not expose unrelated customer records.
3. Disabled WhatsApp capability refuses Inbox, roleless direct URL 403, and no mutation of denied conversation ownership/lock is observed.

Existing `V2WhatsAppServiceRoleHttpBoundaryTest` validates actual read/reply/assign permission/action separation and audit, while `V2RetailWhatsAppInboxSidebarDiscoverabilityTest` and `V2WhatsAppInboxSidebarMigrationTest` cover visible canonical destination and runtime guard.

All applicable Repository Policy, V2 Isolation, PHP/Pint/PHPStan, AR/EN, full Laravel tests, real MySQL/Redis fresh install/upgrade/recovery and `required-ci-gate` MUST pass at exact final PR HEAD before integration. No runtime staging/browser test is claimed; deferred to final program gate #2503/#2750 under owner instruction.

## Cross-app impact

| Surface | Result |
| --- | --- |
| Backend / Dashboard | AFFECTED only by executable QA on real existing Inbox; no runtime behavior/schema/API/route change |
| Customer Flutter | NOT AFFECTED — no customer mobile source, API response, auth or WhatsApp notification contract modification |
| Driver Flutter | NOT AFFECTED — no driver API, GPS, delivery/dispatch or permission changes |
| Van Flutter | NOT AFFECTED — no van routing, visits, B2B sales, settlement or API changes |

B2B VAN ONLY / B2C DRIVER ONLY, finance maker/checker, all tenant/store and role boundaries remain unchanged. No user data, production transport, secrets, release artifact or live environment is altered.
