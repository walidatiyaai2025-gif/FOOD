# FOODEX V2 — T087R01 / #2821: WhatsApp customer-service action authorization

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, T087R01; parent #2503. **Integration: `Foodex_V2` only.** This PR extends verification of the existing live WhatsApp Inbox service. No parallel permissions engine, new route, fake screen, policy weakening, schema mutation, or release.

## Canonical route and action matrix

| Action | Canonical route | Server permission | Additional guard |
| --- | --- | --- | --- |
| List/read | `admin.whatsapp-inbox.index` | `whatsapp.inbox.view` / actor-scoped `whatsapp.conversations.view` | Active authenticated actor, `OperationalTenantScope::allowedStoreIds`, selected conversation must be visible to actor and authorized store |
| Reply | `admin.whatsapp-inbox.reply` | `whatsapp.conversations.reply` | `ConversationService::conversationForActor`: per-conversation store permission, supervisor/unassigned/primary-agent rule; outbound message remains `pending_transport` until delivery is confirmed |
| Assign | `admin.whatsapp-inbox.assign` | `whatsapp.conversations.assign` for first assignment, `whatsapp.conversations.reassign` later | Same conversation/store/agent guard, assignable target, optimistic `lock_version`, transactional history/audit; a visible action does not bypass the server |
| Module unavailable | Existing Inbox routes | None granted | Existing `WhatsAppModuleState` capability/lifecycle fails closed instead of assuming menu presence enables WhatsApp |

No role or permission grants were added. Existing `CUSTOMER_SERVICE_SUPERVISOR`, `CUSTOMER_SERVICE_AGENT` and store-scoped `RETAIL_CUSTOMER_SUPPORT` roles are resolved by their canonical registry. For the positive path, the test enables the already-existing `INTERNAL_TEST` Inbox capability with emergency stop, **not production outbound transport**.

## Acceptance evidence implemented

New `backend/tests/Feature/V2WhatsAppServiceRoleHttpBoundaryTest.php` exercises:

1. Authorized supervisor in Arabic RTL and English LTR: real HTTP GET Inbox, legitimate queued reply with actor attribution, first manual assignment to a permitted agent, persisted `lock_version`, assignment history and audit. A `302` is validated with persisted state; it is **not** used as stand-alone success evidence.
2. Retail support agent scoped to Store A: own-store Inbox HTTP GET, foreign Store B selected conversation denied, foreign POST reply denied, foreign POST assignment denied in both languages, and no messages, history, audit or conversation state mutation on denial.
3. Roleless Dashboard actor: Inbox, reply and assignment fail closed for both locales, without persistent writes. Display/navigation permission is not the HTTP authorization source.

Relevant existing positive and negative behavior is also covered by `WhatsAppAgentInboxTest`, including disabled Inbox, assignment version race, private attachments, agent selection, AR/EN labels, and responsive layout source contracts.

## Product placement and impact matrix

**Placement:** reuse existing Customer Service / WhatsApp Inbox (`admin.whatsapp-inbox.index`), not a new page or Sidebar item. **Navigation owner:** existing authorized service/navigation configuration; no change to reachability, scopes, active-context behavior, responsive layouts or translation assets.

- **Backend / Dashboard — affected (QA only):** new isolated executable HTTP/database authorization regression. App/controllers/routes/views are unchanged. Direct-route protection and AR/EN response direction are asserted.
- **Customer app — NOT AFFECTED:** no Customer Flutter source, API request/response, identity, customer account, prices or notification/transport contract changed.
- **Driver app — NOT AFFECTED:** no driver API, GPS, B2C assignment, delivery or dispatch contract changed.
- **Van app — NOT AFFECTED:** no Van API, visits, B2B order dispatch, territory or inventory contract changed. B2B remains VAN ONLY; B2C remains DRIVER ONLY.

**Security:** no secrets, phone numbers or production-account identifiers are published, no real WhatsApp message is transmitted and no production database is accessed. PHP test fixtures use synthetic `example.test` identities.

## Remaining gate and closure boundary

The PR must pass on **the latest exact SHA**: Repository Policy, FOODEX V2 Isolation, AR/EN Localization, Backend Syntax/Pint/PHPStan/Security/OpenAPI, full Laravel, MySQL/Redis fresh-install/upgrade/recovery and terminal `required-ci-gate`. Record actual workflow URLs and result in the Issue/PR after observation; no inferred green checks.

**Issue #2821 remains OPEN** until independent isolated-staging real browser acceptance verifies Supervisor and Retail Agent flows in Arabic RTL / English LTR, 320–1280px responsive, screen-reader focus, keyboard and errors, WhatsApp enabled/disabled, real assignment audit and any necessary owner approval of authority boundaries. CI evidence does not substitute for that operational review.

**Release boundary:** no PR/merge to `main`, no deployment, tag, APK publication, production call or cPanel action.
