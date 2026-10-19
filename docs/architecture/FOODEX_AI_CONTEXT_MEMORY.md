# FOODEX Assistant Context, Session and Memory Contract

Issue: #2216  
Parent: #2208  
Depends on: #2211 identity/RBAC/consent and #2215 bounded agent orchestration  
Version: 1.0

## Authority boundary

FOODEX treats browser page context and conversation state as hints, never authorization. Every Assistant turn re-resolves the authenticated user, current roles/permissions, store/channel scope and retailer sell-out consent before any tool call. The model receives no authority to widen that scope.

## Signed page context

The server resolves the requested management page into a bounded page context containing the canonical registered management route/path, domain, channel, active store and authorized entity identifiers. Numeric route parameters such as store/order/customer/driver/product are extracted from Laravel's registered route match; browser-supplied identifiers remain hints only and route-derived identifiers take precedence. Supported entity identifiers include store, order, customer, driver, product and B2B account references when they are visible inside the caller's current scope.

The server returns a short-lived HMAC-authenticated context token that is bound to the authenticated user and includes the resolved locale, role codes, business scope and page context. A token is only a tamper-evident transport envelope. On every later turn its page context is passed back through `AssistantPageContext` and `AiPrincipalContextResolver`, so changed permissions, store access or sell-out consent take effect immediately.

Raw browser IDs never become authorization. A forged ID fails the same server-side visibility checks even when no token is supplied.

## Conversation state and session lifetime

FOODEX reuses `assistant_conversations`, `assistant_messages` and `assistant_conversation_state`; no second chat/session database exists.

Conversation rows are retained for `assistant.retention_days` and stale rows are pruned for the current user whenever the Assistant is entered. Short-lived follow-up state is valid only for `assistant.session_minutes`. When that session window expires, prior entity/window references are discarded before the next turn.

Conversation state may persist only:
- the last intent/tool key;
- normalized time-window identifiers/bounds;
- authorized entity identifiers/references;
- pending clarification markers;
- locale;
- allowlisted presentation preferences.

It must not persist transactional totals, balances, stock quantities, sell-in/sell-out facts, provider prompts, API keys, database credentials or model reasoning.

## Redaction

Messages are processed using the user's submitted text for the current turn, but persisted message content is redacted for common Authorization/Bearer/API-key/token/secret/password patterns. Redaction is a storage safeguard; it is not an authorization boundary.

## Follow-ups

Deterministic follow-up resolution may carry forward only the still-valid entity/window identifiers above. Relative windows include today/yesterday, this/last week and this/last month. A follow-up such as "compare it with last month" may reuse the intended entity and switch to the requested window, but all requested business facts are read again through the approved tools.

## Durable preferences

Only allowlisted presentation preferences are durable. Current v1 keys are locale and response style (`concise` or `detailed`). Unknown keys are dropped. In particular, client input cannot persist business facts, IDs, permissions, secrets or provider configuration as "memory".

## Freshness invariant

Conversation history is never a business source of truth. Every transactional or analytical answer must execute the authoritative FOODEX tool/read-model path for the requested current window. Stored conversation content can be displayed to the user but is not re-ingested as a factual database substitute.
