# V2 #2532 / P202 — Customer Service distribution rules

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P202, Customer & Sales; parent #2503.
**Integration:** `Foodex_V2` ONLY. `main`, release, tags, live cPanel, production and publication are forbidden.

## Reuse → Extend → Compose → New Screen

**Existing authority**: WhatsApp Inbox `admin.whatsapp-inbox.index`; auto-route engine `App\\Domain\\WhatsApp\\AgentAssignmentEngine`; persisted `whats_app_assignment_rules`; `ConversationService::autoAssign`. P201 (#2531) already owns *manual* conversation assignments and related supervisor/HTTP tests. Routing Policies `admin.field-operations.routing` instead govern the B2B Van fleet and are **not** a P202 conversation-rule editor.

**Placement decision:** Extend the existing authenticated WhatsApp Inbox/Supervisor workspace with a rules tab once the shared route/sidebar file fences are released. Do NOT create a parallel fleet Routing Policies screen or a decorative Sidebar-only link.

## Implemented narrowly in this checkpoint

- Auto-assignment filters candidates by *both* effective `whatsapp.conversations.view` **and** `whatsapp.conversations.reply` for the exact conversation store; a view-only actor cannot be chosen.
- The existing `assertEligible` direct validation uses the same permission pair, retaining its existing localized `agent_id` validation error.
- Preferred rule `configuration.agent_ids` cannot resurrect a store-scoped reply DENY; an authorized agent is chosen from the safe eligible pool, otherwise the conversation remains `WAITING_FOR_AGENT` rather than silently assigning it to an incapable user.
- Focused `V2ConversationDistributionRuleAuthorizationTest` covers B2B effective reply DENY, another store's unaffected authority, B2C store isolation, rule preferred-ID priority, no-eligible fallback through real `ConversationService`, and AR/EN localized errors. No new database schema or public API.

## Still REQUIRED before issue completion or PR merge

1. Confirm exact source P202 row and its policy controls with the authorized source-to-Issue manifest. Identify supported `strategy`, `priority`, scope `store_id`, `channel`, `conditions` and `agent_ids`; define safe validation and precedence. Existing `conditions` JSON is currently persisted but not evaluated by `choose`: do not advertise conditional targeting as operational.
2. Deliver an authorized and reachable management UX in the **existing** Inbox/Supervisor workspace: list/search, create, edit, deactivate, priority and strategy previews with a first-class agent/store selector; no raw JSON/IDs for business users. Ensure transactional validation, permission scoping, audit, conflict handling and rollback/recovery of invalid rules.
3. Ensure Sidebar/route/module readiness uses one authoritative decision; direct GET/POST/PATCH/DELETE must be checked under real WhatsApp runtime capability, actor permission, store/channel, cross-tenant DENY, inactive/roleless users and multiple roles. Never weaken `whatsapp.capability:agent_inbox`.
4. Test AR/EN RTL/LTR, responsive 320–1280px Dashboard accessibility, disabled/empty/validation states, real persistence and rule matching across channels, including negative tests for unauthorized rule writes and cross-store previews.
5. Verify latest exact-head `Repository Policy`, `FOODEX V2 Isolation Gate`, `FOODEX Required CI Gate` (all applicable Laravel, Pint/PHPStan/security, locale and MySQL/Redis install/upgrade/recovery). Do not merge this partial PR until complete, including the blocked route/sidebar entry points.
6. Manual UX/staging/device UAT: **DEFERRED_TO_V2_FINAL** under #2503/#2750; only manual acceptance is deferred, not source/tests/CI.

## Cross-application impact matrix

| Surface | Impact at this checkpoint | Evidence |
| --- | --- | --- |
| Dashboard | Existing WhatsApp Inbox auto-assignment eligibility changes; no new UI yet | AgentAssignmentEngine and Laravel regression |
| Backend | Effective reply permission enforced before choosing an auto-agent | Existing store-aware User RBAC, existing ConversationService |
| Customer Flutter | Not affected — no public customer API/response changes | No changes to API routes, customer client or order/dispatch lifecycle |
| Driver Flutter | Not affected — no Driver API or dispatch changes | B2C DRIVER ONLY unchanged |
| Van Flutter | Not affected — no Van API or order routing changes | B2B VAN ONLY unchanged |

**File ownership:** PR #3321 currently owns AdminNavigation.php and sidebar/admin localization; PR #3309 currently owns web.php; PR #3311 owns FieldOperationsController and its Blade/lang. Do not alter any peer-owned path before proper handoff. The checkpoint touches only AgentAssignmentEngine, new P202 test and this QA document. Issue must remain OPEN; no claim of full P202 UI acceptance or production readiness.
