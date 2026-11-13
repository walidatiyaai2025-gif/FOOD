# FOODEX V2 #2532 / P202 — Conversation distribution rules

**Branch:** `feat/2532-v2-conversation-distribution-rules`. **PR:** #3324 targeting **Foodex_V2 ONLY**. **Owner source:** P202 (Customer & Sales), parent #2503. No main, release, tags or production.

## Placement and source authority

Reuse and extend the canonical WhatsApp Supervisor workspace: `admin.whatsapp-supervisor.index` contains the discoverable rules entry; `admin.whatsapp-supervisor.rules.index` uses its existing Admin Sidebar, AR/EN translations, authenticated admin group and `whatsapp.capability:agent_inbox` middleware. No new Sidebar group or separate WhatsApp routing engine. P201's manual assignment remains separate; Van `admin.field-operations.routing` remains B2B fleet routing, NOT Customer Conversation Distribution.

## Implemented contract

- `DistributionRuleManager` lists rules per authorized store; eligible actors must have effective `whatsapp.conversations.assign` AND `whatsapp.customer_service.supervise`, or be an authenticated active SUPER_ADMIN. Global role alone does not override actual allowed-store IDs. Foreign store access fails closed.
- Authorized supervisors can list/create/edit/activate/deactivate **store-scoped** rules with explicit priority, `round_robin`/`least_loaded`, and preferred agent Lookup. The chosen store is from active authorized Master Data, not a text ID. Agent IDs in POST are validated against live exact-store VIEW+REPLY authority; an agent lacking either permission cannot be routed a conversation.
- The canonical `AgentAssignmentEngine` enforces view+reply before any auto-assignment; `ConversationService::autoAssign` leaves an unassigned case in `WAITING_FOR_AGENT` if no agent is eligible. Existing preferred-agent rules and fallback behavior remain compatible.
- New managed rules store a `managed_by=v2_p202` marker in existing `whats_app_assignment_rules.configuration`; existing advanced/legacy rules are visible but read-only, preserving unsupported `conditions`. Global/other-store rules cannot be modified via the new editor.
- Transactional changes write `audit_logs` creation/update/status events; a SHA-256 row-revision token rejects stale submissions (not a package integrity signature). Status updates never rewrite the rule configuration. Validation messages are localized AR/EN.
- No migration, customer mobile API, Driver or Van Flutter changes. Customer: NOT AFFECTED (no public API or identity/checkout contract change). Driver: NOT AFFECTED (B2C DRIVER ONLY remains). Van: NOT AFFECTED (B2B VAN ONLY remains).

## Test coverage and acceptance

- `V2ConversationDistributionRuleAuthorizationTest`: B2B/B2C effective VIEW/REPLY, store-level DENY, rule target fallback, real unassigned queue, Arabic/English error.
- `V2ConversationDistributionRulesHttpTest`: authenticated Supervisor discoverability, AR/EN RTL/LTR, create/edit/disable/enable, actual rule engine, audit, concurrent stale revision, roleless/view-only/foreign store deny, legacy readonly and operational runtime rejection.
- Dashboard responsive layout uses shared `_brand-components`, `foodex-admin-layout`, `foodex-page-toolbar`, cards and server-side pagination (15); 320–1280 px behavior relies on responsive CSS. Formal device/browser manual acceptance remains **DEFERRED_TO_V2_FINAL** (#2503/#2750), not a substitute for automated source/CI gates.

**CI status:** run exact final SHA Repository Policy, FOODEX V2 Isolation, FOODEX Required CI (PHP syntax/Pint/PHPStan/security/OpenAPI, Laravel Feature suite, AR/EN, MySQL/Redis install/upgrade/recovery) and inspect every failing job; do not merge or close until all mandatory checks PASS and source acceptance is verified.

**File coordination:** #2527/PR #3321 actively owns `AdminNavigation.php` (untouched). #2633/PR #3309 has a handoff-ready lease; P202 only adds disjoint WhatsApp Supervisor routes in `backend/routes/web.php`, leaves Finance/AR paths untouched, and must reconcile exact-base drift/mergeability before merging.
