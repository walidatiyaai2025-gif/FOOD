# FOODEX V2 #2531 — P201 conversation distribution and assignment authority

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P201; parent #2503. **Sole integration:** `Foodex_V2`. No `main` promotion, production deployment, tag or release.

## Route placement: reuse existing Inbox action, not a second screen

- **Business domain:** Customer Service & WhatsApp; **entity:** WhatsApp conversation assignment.
- **Actors:** active Customer Service Supervisor with actual `whatsapp.conversations.assign` or `whatsapp.conversations.reassign` for the conversation's **same channel and store**, and authorized in-scope service agents. A global Wholesale Customer Service Supervisor is **not** automatically a Retail conversation writer; a store-assigned `RETAIL_CUSTOMER_SUPPORT` member may act within its own assigned B2C store. Role label is not itself an authorization grant.
- **Navigation:** existing visible Sidebar Customer Service group → `whatsapp_inbox` → `admin.whatsapp-inbox.index`; selected conversation exposes an existing localized `manage_assignment` context action. Placement is **Reuse → CONTEXT_ACTION**, not a new standalone Sidebar item or `DIRECT_URL_ONLY`. Back path is the selected Inbox conversation.
- **Mutation:** existing `PATCH admin.whatsapp-inbox.assign` with conversation ID from selected UI, `agent_id` from the canonical assignable-agent roster, optional backup, required `expected_version` and reason. No business user must type an internal ID.
- **Authorization:** `whatsapp.capability:agent_inbox` checks runtime module availability; `WhatsAppInboxController::actor()`, `ConversationService::conversationForActor()` and `AgentAssignmentEngine::assertManuallyAssignable()` enforce active identity, action permission, store-scoped conversation and eligible recipient. UI/Siderbar visibility must never confer an access right.
- **Scope:** each conversation retains its actual `store_id`, channel, primary agent, lock version. No cross-store agent assignment; global supervisor authority does not transform a Retail agent into a Wholesale/other-store agent. B2B fulfillment remains Van-only; B2C fulfillment remains Driver-only.

## Automated HTTP acceptance

`backend/tests/Feature/V2ConversationDistributionSidebarAuthorityTest.php` uses seeded canonical roles, isolated fake B2B/B2C stores and disposable conversations under `RefreshDatabase`.

1. AR RTL and EN LTR: a permitted supervisor discovers exactly one authorized existing WhatsApp Inbox route and opens the selected conversation with translated assignment action. There is no second distribution page or duplicate navigation destination.
2. Authorized real HTTP assignment: `PATCH /admin/whatsapp/inbox/{conversation}/assignment` creates durable primary-agent association, lock-version transition, assignment history and audit event; reassignment creates an additional versioned history/audit entry.
3. A stale expected-version attempt returns a validation error and changes neither assigned agent nor assignment-history row count.
4. A genuinely authorized B2C Store A `RETAIL_CUSTOMER_SUPPORT` writer attempts assignment to a Store B-only support agent; the canonical recipient-scope check returns the `agent_id` validation error without changing conversation state or history. The realistic test fixture sets `business_profile=RETAIL_STORE` on B2C stores.
5. A Wholesale-only global Customer Service Supervisor cannot perform a B2C Store A assignment at all (HTTP 403 before agent eligibility), preserving the actor channel boundary and immutability of assignment state.
6. A disabled module returns 404 for a direct assignment attempt. A roleless authenticated user receives 403 and sees no Sidebar Inbox link.

Existing `WhatsAppAgentInboxTest`, `V2RetailWhatsAppInboxSidebarDiscoverabilityTest` and `V2CustomerConversationsInboxScopeTest` cover the broader queue, store-selected list, roster and Inbox read-only paths; P201 adds the distinct real assignment HTTP boundary. Existing policy also protects individual conversation selection and the supervisor/customer-service role profile.

## AR/EN, accessibility, mobile and release boundary

Existing Inbox translations and semantic controls are reused. This change adds **only** a Laravel Feature regression and scoped QA contract, not a new page or strings; selected conversation/assignment remains in the existing responsive three-pane Inbox with existing label/keyboard controls. No manual browser accessibility or device testing is claimed here; consolidated V2 staging UX/a11y audit remains under #2750/#2503 per owner-approved deferral.

| Surface | Impact classification |
| --- | --- |
| Backend / Dashboard | **Test-only addition** to verify existing authenticated WhatsApp assignment API, exact permissions, tenant/channel and audit invariants |
| Customer Flutter | **Not affected:** no customer conversations API, chat payload, checkout, order/price or login change |
| Driver Flutter | **Not affected:** no Driver assignment, GPS, POD, B2C dispatch or app source change |
| Van Flutter | **Not affected:** no Van visits, route, dispatch, order, supplier, offline or app source change |
| Finance / SoD | **Not affected:** no ledger, settlement, refund, approval or treasury behavior change |

## Exact-head CI and completion gate

One Issue #2531, branch `test/2531-v2-whatsapp-conversation-routing-scope`, PR to `Foodex_V2` only. Required evidence before merge: Repository Policy, FOODEX V2 Isolation, full applicable Laravel tests, PHP syntax/Pint/PHPStan, localization, security, MySQL/Redis install-upgrade-recovery and terminal `required-ci-gate` all SUCCESS on **the unchanged final PR head**. A red test is a same-branch fix, never a bypass. After verified merge, close only if real P201 source coverage is accepted; retain #2503 open. No `main`, tag, APK distribution or production deployment.
