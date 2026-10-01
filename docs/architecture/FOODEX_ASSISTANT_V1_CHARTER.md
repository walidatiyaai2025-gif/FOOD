# FOODEX Assistant V1 Project Charter

Status: **Authoritative execution charter**
Track: **AI-V1**
Runtime target: **existing Laravel management dashboard on cPanel**
LLM usage in V1: **none**
External AI API cost in V1: **zero**
Production release blocker while under development: **no**

---

## 1. Mission

Build a persistent conversational assistant inside the FOODEX management dashboard that looks and behaves like normal chat while using deterministic intent/entity/dialogue logic and authoritative FOODEX data.

V1 must provide useful business and operational answers without OpenAI, Gemini, Claude, local LLMs, paid AI APIs, GPU services, or any other model dependency.

The architecture must make a future LLM an optional replaceable brain, not a rewrite of the assistant UI, tools, security, context, conversation history, or business-data layer.

---

## 2. Non-negotiable product behavior

The Assistant must:

- appear persistently in the management sidebar as a FOODEX assistant character;
- open as a desktop side drawer and mobile full-screen sheet without navigating away;
- support Arabic and English, RTL/LTR, and mixed business terminology;
- preserve conversation state across dashboard navigation and refresh;
- accept ordinary conversational phrasing rather than command syntax;
- support multi-turn references such as "دول", "ده", "قارنهم بالأسبوع اللي فات", and equivalent English follow-ups;
- understand current-page context for supported Order, Customer, Store, Driver and Product pages;
- answer only from authoritative FOODEX data or deterministic computed facts;
- expose useful deep-link actions such as Open Order, Open Store, Show Delayed Orders and Compare;
- say clearly when an intent is unsupported rather than inventing an answer;
- remain read-only in V1.

No free-form SQL, shell execution, arbitrary PHP execution, business writes, or hidden remote calls are permitted.

---

## 3. V1 architecture

```text
Management Dashboard
       |
       v
Persistent Assistant UI
       |
       v
Assistant HTTP API
       |
       v
AssistantBrainInterface
       |
       +-- DeterministicBrain  <-- V1
       |
       +-- LlmBrain            <-- future only
       |
       v
Intent + Entity + Dialogue Engine
       |
       v
Tool Registry
       |
       +-- Sales / Orders / Stores / Customers / Products
       +-- Drivers / Cancellations / Inventory / Daily Brief
       |
       v
Existing FOODEX domain/services/repositories
       |
       v
Authoritative database
```

The current Laravel application remains the authority. No separate Python or Node service is required for V1.

---

## 4. Deterministic conversational engine

Every message follows this pipeline:

1. preserve the original message for display/audit;
2. normalize Arabic/English text for matching only;
3. classify intent using deterministic weighted patterns/synonyms;
4. extract entities and time windows;
5. combine explicit entities with authorized page context;
6. resolve multi-turn references from conversation state;
7. validate authorization/scope server-side;
8. choose a typed Assistant Tool;
9. execute the tool against authoritative FOODEX data;
10. compose a localized structured response;
11. persist conversation state and result references.

Confidence behavior:

- high confidence: execute directly;
- medium confidence: ask one focused clarification question;
- low confidence: return supported-topic fallback + suggested prompts.

The server never trusts a store/entity id merely because the browser supplied it.

---

## 5. Required V1 intents/tools

### Business tools

- `sales.summary`
- `sales.compare`
- `orders.summary`
- `orders.lookup`
- `stores.summary`
- `stores.compare`
- `customers.summary`
- `customers.activity`
- `products.performance`

### Operations tools

- `orders.late`
- `orders.cancelled`
- `cancellations.summary`
- `drivers.status`
- `drivers.assignments`
- `inventory.alerts`
- `brief.daily`

Tool inputs and outputs must be typed/validated. The deterministic brain may select only registered tools.

---

## 6. Conversation persistence

Minimum persistent data model:

- `assistant_conversations`
- `assistant_messages`
- `assistant_conversation_state`

Conversation state must carry only the context necessary for follow-up resolution, including last intent, last period, last authorized entities, last tool, result references, pending clarification slots and locale.

Retention must be configurable. Sensitive payloads/tokens must not be stored in assistant history.

---

## 7. HTTP contract

Expected management routes:

- `GET /admin/assistant/bootstrap`
- `POST /admin/assistant/conversations`
- `GET /admin/assistant/conversations/{conversation}/messages`
- `POST /admin/assistant/conversations/{conversation}/messages`
- `DELETE /admin/assistant/conversations/{conversation}`
- `POST /admin/assistant/conversations/{conversation}/clear`

A response may contain:

- localized message;
- intent key and confidence;
- typed data cards;
- safe deep-link actions;
- suggested follow-up prompts;
- conversation state metadata;
- generation timestamp.

No endpoint in V1 may perform a business-domain write action.

---

## 8. Security and tenant isolation

V1 is read-only.

Required authorization behavior:

- add/use an Assistant access permission such as `assistant.use`;
- every Assistant Tool must apply the same B2B/B2C/store/tenant visibility rules as the authoritative FOODEX feature it represents;
- SUPER_ADMIN behavior follows existing platform policy;
- cross-store and cross-channel leakage must be covered by automated tests;
- page context is a hint, never authorization;
- rate-limit chat requests;
- redact secrets/tokens from logs and stored context;
- never expose raw SQL or internal exception traces to users.

If Assistant functionality is unavailable, normal FOODEX management workflows must remain unaffected.

---

## 9. Feature flags and operational safety

Minimum controls:

```text
ASSISTANT_ENABLED=false
ASSISTANT_READ_ONLY=true
ASSISTANT_RETENTION_DAYS=90
ASSISTANT_CACHE_SECONDS=60
ASSISTANT_RATE_LIMIT=30
```

Production default is disabled until explicitly enabled.

The Assistant must fail closed: disabling the Assistant removes/hides its UI and routes/functionality without breaking Orders, Stores, Customers, Drivers, B2B, B2C or other management modules.

---

## 10. cPanel runtime contract

V1 must fit the existing cPanel-hosted application without a paid AI dependency.

Preferred runtime:

- existing PHP/Laravel process;
- existing database;
- Laravel cache;
- Laravel scheduler;
- one cPanel cron entry invoking `php artisan schedule:run` when scheduled precomputation is required.

V1 must not require:

- GPU;
- persistent LLM process;
- Python daemon;
- Node daemon;
- external vector database;
- paid API key;
- WebSocket service.

Expensive business summaries should use indexed queries, bounded result sets, caching and/or scheduled precomputation rather than unbounded live scans.

---

## 11. Release Isolation Contract

This section is mandatory for every AI-V1 child Issue and PR.

### Separate tracks

FOODEX has independent work tracks:

- `[PLATFORM-BUG]` — platform defect, normally targets `main`;
- `[HOTFIX]` — production-critical platform defect, targets `main` with highest release priority;
- `[AI-V1]` — Assistant feature work, targets the Assistant integration branch;
- `[AI-BUG]` — defect isolated to Assistant work/runtime; while Assistant is not production-required, it does not block normal FOODEX releases;
- `[AI-GOV]` — Assistant governance/coordination.

### Integration branch

Assistant child implementation PRs target:

```text
feat/assistant-v1-integration
```

They do **not** target `main`.

Normal platform bug fixes, hotfixes and releases continue from `main` independently.

### Platform always wins shared-file conflicts

If an Assistant lane and a platform bug need the same file:

1. the platform bug/hotfix owns the shared file on `main`;
2. merge/fix/release the platform work first;
3. do not wait for Assistant CI or Assistant completion;
4. afterwards sync the Assistant integration branch from latest `main`;
5. the Assistant lane owns any resulting adaptation/conflict.

The Assistant adapts to the platform. The platform never waits for the Assistant.

### Release identity fence

No AI-V1 child Issue may change, unless it is the explicit final integration/release task:

- `VERSION`;
- production release identity;
- Customer app version;
- Driver app version;
- release distribution artifacts;
- production activation state;
- unrelated deployment manifests.

AI CI failures are not normal FOODEX production release blockers.

### Database fence

Assistant schema changes must be additive and isolated where practical.

During V1 child work, do not rename/drop platform tables/columns or redesign core Order/Customer/Store contracts merely to support the Assistant.

---

## 12. Branch and dependency topology

The special integration branch is a feature-train target, not an implementation task branch.

```text
main
 |
 +-- fix/<platform-bug>
 +-- hotfix/<production-bug>
 +-- normal release work
 |
 +-- feat/assistant-v1-integration
       |
       +-- feat/<issue>-assistant-foundation
       +-- feat/<issue>-assistant-conversation-engine
       +-- feat/<issue>-assistant-business-tools
       +-- feat/<issue>-assistant-operations-tools
       +-- feat/<issue>-assistant-chat-ui
       +-- feat/<issue>-assistant-api-integration
       +-- test/<issue>-assistant-v1-acceptance
```

Foundation merges first.

After foundation, Conversation Engine, Business Tools, Operations Tools and Chat UI may run in parallel when their ownership fences are respected.

API integration starts after the relevant engine/tool contracts exist.

Final acceptance runs after all implementation lanes are merged to the integration branch.

Only the explicit final-main-integration task may open the Assistant integration PR toward `main`.

---

## 13. Shared-file ownership fences

To preserve parallelism:

### Foundation lane owns

- Assistant models/migrations;
- Assistant contracts/interfaces;
- Assistant configuration;
- Assistant permission registration.

### Conversation lane owns

- deterministic brain;
- intent matcher;
- entity/time parser;
- dialogue-state/follow-up resolution.

### Business tools lane owns

- Sales/Orders/Stores/Customers/Products Assistant tools and Assistant-specific query/repository adapters.

### Operations tools lane owns

- late-order/cancellation/driver/inventory/daily-brief Assistant tools and Assistant-specific adapters.

### UI lane owns

- Assistant Blade partials;
- Assistant JS/CSS/assets;
- robot/avatar presentation;
- drawer/sheet conversation UX.

If `_sidebar.blade.php` or another shared platform UI file is actively owned by an unrelated platform issue such as driver tracking, the Assistant UI lane prepares isolated partials/assets first and waits only for that shared-file delta. The platform issue is not delayed.

### API integration lane owns

- Assistant controller/request/response HTTP layer;
- Assistant routes;
- authoritative page-context handoff and deep-link response integration.

This keeps shared `routes/web.php` ownership in one Assistant lane.

---

## 14. Automatic execution / no-chat dependency

GitHub is authoritative. No execution-critical state may exist only in ChatGPT chat.

The Assistant umbrella must be coordination-only and must contain the full child queue.

Every child Issue must include:

- `<!-- foodex-worker:managed -->`;
- Parent umbrella reference;
- exact classification;
- `Production release blocker: NO`;
- base/target branch;
- scope and file ownership;
- dependencies;
- acceptance criteria;
- required tests;
- Release Isolation Contract;
- worker-state block.

A worker must solve repository-local failures itself and must not ask the repository owner to intervene for:

- CI failures;
- lint/test failures;
- merge conflicts;
- branch drift;
- missing code/tests/docs;
- stale worker/chat;
- normal rebasing/syncing.

If a worker/chat dies, another worker uses the same Issue/branch/PR under `AGENTS.md` handoff rules.

For the Assistant umbrella, the command:

```text
FOOD #<assistant-umbrella> AUTO-HANDOFF
```

means continuously drain the entire Assistant V1 mission, not one child task.

A mission worker must continue selecting safe Assistant lanes until:

- all repository-local Assistant work is complete; or
- only genuine external gates remain.

No chat interruption is allowed to create a replacement implementation branch or duplicate PR.

---

## 15. Platform bug precedence while Assistant work is active

At any point during Assistant implementation, a new production/platform bug may be created and fixed normally.

Assistant workers must not claim unrelated platform bugs from the Assistant umbrella.

Platform workers must not be forced to merge the Assistant integration branch or wait for Assistant checks.

Recommended naming:

- `[PLATFORM-BUG] ...`
- `[HOTFIX] ...`
- `[AI-BUG] ...`
- `[AI-V1] ...`

If a defect is ambiguous, classify by blast radius:

- broken without Assistant enabled => platform bug;
- only Assistant code/path wrong => AI bug;
- Assistant exposes a pre-existing platform defect => create/fix platform bug on `main`, then adapt Assistant.

---

## 16. Main synchronization

The integration branch must periodically absorb latest `main`, especially after:

- production hotfix;
- normal release completion;
- auth/permission changes;
- shared dashboard changes;
- database/domain contract changes.

Synchronization happens **after** the platform change is safely merged/released.

It never becomes a prerequisite for the platform release.

---

## 17. Acceptance matrix

Assistant V1 is not complete until automated evidence covers:

### Conversation

- Arabic intent recognition;
- English intent recognition;
- date/time parsing;
- multi-turn follow-up resolution;
- clarification behavior;
- unsupported-intent fallback;
- current-page context.

### Data correctness

- deterministic seeded sales result;
- deterministic seeded order result;
- store comparison;
- customer summary;
- product performance;
- driver/operations scope;
- inventory alert;
- daily brief.

### Security

- B2B isolation;
- B2C isolation;
- store isolation;
- unauthorized entity rejection;
- no free-form SQL;
- no business write action;
- no external AI/API-key requirement;
- rate limiting;
- feature-disable behavior.

### UX

- persistent desktop drawer;
- mobile sheet;
- refresh/navigation persistence;
- Arabic RTL;
- English LTR;
- loading/typing/error/empty states;
- safe deep-link actions.

### Performance/operations

- bounded query behavior;
- cache behavior;
- scheduler tasks where applicable;
- cPanel-compatible rollout documentation.

---

## 18. Atomic implementation queue

The umbrella must create and track these atomic lanes:

1. **Foundation** — contracts, schema, configuration, permission, feature flags.
2. **Conversation Engine** — deterministic brain, intents, entities, time parser, dialogue state.
3. **Business Tools** — sales, orders, stores, customers, products.
4. **Operations Tools** — late orders, cancellations, drivers, inventory, daily brief.
5. **Persistent Chat UI** — assistant character, drawer/sheet, history, RTL/LTR, action cards.
6. **API + Context Integration** — routes/controllers, page context, deep links, complete chat orchestration.
7. **Security/Performance/CI Acceptance** — tenant isolation, seeded correctness, performance and no-external-AI gates.
8. **Final Main Integration** — sync latest main, prove disabled-by-default isolation, open integration PR to main, merge only when platform-safe.

The umbrella itself has no implementation branch.

---

## 19. Definition of Done

The Assistant V1 mission is complete only when:

- all child implementation/acceptance Issues are merged/closed;
- Assistant conversation is persistent and natural-looking without an LLM;
- required business/operations tools return authoritative data;
- tenant/store/channel isolation is proven;
- Assistant is read-only;
- external AI requests/API keys are not required;
- the integration branch has absorbed latest `main`;
- final integration to `main` is green and merged without delaying unrelated releases;
- production default remains `ASSISTANT_ENABLED=false` unless an explicit rollout decision changes it;
- obsolete Assistant task branches are cleaned up safely;
- the umbrella records final evidence and closes.

External cPanel activation/deployment evidence must not be fabricated. Repository work should finish independently and package/document any remaining external action precisely.
