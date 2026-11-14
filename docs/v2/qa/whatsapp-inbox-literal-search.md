# #3609 — P082 WhatsApp Inbox literal customer search

## Source, canonical placement, authority

- Source: V2 P082 (View/search under effective authorization), P200 (Customer Conversations) under parent #2503. The canonical Customer Conversations Inbox was already integrated by #2530 / PR #3069, and WhatsApp View/Reply/Assign RBAC was accepted by #2821 / PR #3036.
- Target integration: **Foodex_V2 only**. No main, production, tags, releases, APKs or cPanel access.
- Domain: Marketing & Communications > Customer Conversations / WhatsApp; role: effective `whatsapp.conversations.view` and assigned store/channel authorization; entity: scoped WhatsApp conversation and customer name/contact. Goal: search free-text customer data literally, not as a SQL pattern.
- Placement: **REUSE** existing protected `admin.whatsapp-inbox.index` conversation rail/list and querystring `q`, 40/page; no new UI, route, sidebar, action or localization strings. Existing AR/EN RTL/LTR desktop/tablet/mobile web behavior is unchanged.
- Fixed-columns parameter-bound SQL `LIKE ? ESCAPE '!'` across `customers.name`, `customers.email`, `customers.phone`, escaping literal `!`, `%`, `_`; portably supported on SQLite and MySQL/MariaDB without backslash-mode assumptions. This is list result correctness, not evidence of a tenant bypass.

## HTTP regression and data-scope evidence

`backend/tests/Feature/V2CustomerConversationsInboxScopeTest.php` provides existing authoritative `RefreshDatabase` Retail WhatsApp fixtures, role binding, direct HTTP authorization. New tests assert:

- Literal percent in a customer name returns only actual `%` match, not same-store wildcard lookalikes; foreign B2C store still inaccessible.
- Literal underscore in customer name excludes similar characters; Arabic RTL and English LTR actual Inbox GET are asserted.
- Exact customer email containing `_` and `%` matches as text and excludes false wildcard matches.
- Combined `!`, `%`, `_` in phone search matches the literal phone and excludes wildcard lookalikes.
- Normal text search retains matches; resolved/status filtering excludes rows; explicitly requested unassigned Retail store returns 404.
- Read-only search GET preserves conversation/customer counts and audit event count. Existing P200/RBAC tests still cover disabled-module 404, roleless 403, foreign selected-conversation 403, store role assignments, no phantom state.
- Existing `conversationQuery()`, `authorizedStoreIds()`, selected-conversation authorization, assignment/status/channel filtering, querystring and pagination, message/send/resolve/agent transactions are **not** modified.

## Cross-surface matrix

| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend | affected | Search term predicate in existing authenticated controller only |
| Dashboard | affected | Correct scoped Inbox result matching on existing search field |
| API | not affected | No public or mobile API, DTO, permissions, action routes or request schema modified |
| Customer App | not affected | No Flutter, order, shopping, notifications or login delta |
| Driver App | not affected | No dispatch, wallet, driver assignment or mobile delta |
| Van App | not affected | No B2B Van route, consignment or mobile delta |

B2B VAN ONLY / B2C DRIVER ONLY unchanged; no database schema or production config changes. Existing UI localization and responsive view remain intact, verified through real HTTP AR/EN tests.

## Acceptance / current gate

Only 3 owned paths: `WhatsAppInboxController::index` q predicate; new HTTP tests in `V2CustomerConversationsInboxScopeTest`; this QA document. Require PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel, AR/EN localization, MySQL/Redis deployment acceptance, Repository Policy, V2 Isolation and terminal Required CI exact-final-HEAD **SUCCESS**. Review source diff and confirm no parallel ownership; merge PR with expected SHA **only to Foodex_V2** after all applicable checks, then verify integration before closing leaf `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Retain final human/staging/mobile-web UX UAT under #2503; do not claim production readiness.
