# FOODEX Architecture

## Monorepo shape

```text
FOOD/
├─ backend/                 Laravel 12 API + Dashboard
├─ apps/
│  ├─ customer_app/        Flutter Customer application
│  ├─ driver_app/          Flutter Driver application
│  └─ van_app/             Flutter Van application
├─ database/               repository-level database assets
├─ packages/               shared packages
├─ docs/                   authoritative product/quality/release contracts
├─ scripts/                CI/release/validation automation
├─ .github/workflows/      GitHub Actions
├─ Release/                synchronized distributable artifacts
└─ .ai/                    agent orientation + execution playbooks
```

## Backend

Runtime: PHP 8.2+ / Laravel 12.

Important layers in `backend/app/`: `Domain/`, `Actions/`, `Http/`, `Models/`, `Policies/`, `Repositories/`, `Services/`, `Jobs/`, and `Support/`.

Routes are split across `backend/routes/api.php`, `web.php`, `notifications.php`, `coupons.php`, and `console.php`.

Prefer domain/application services for business transitions over duplicating business rules in controllers, Blade templates or mobile clients.

## API boundary

- Canonical API prefix: `/api/v1`.
- OpenAPI is the contract source.
- Authorization and store/channel scope must be enforced server-side.
- Mobile clients must not become the authority for order, finance, assignment or delivery state transitions.
- Retriable writes should be idempotent where duplicate requests are realistic.
- Push/deep links are navigation signals, never authorization grants.

## Dashboard boundary

The Management Dashboard is a business application, not a raw admin CRUD surface.

Mandatory contract: `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`.

Key implications: business-domain navigation, exactly one shared Admin global header per canonical Dashboard page, page-level title/actions in `foodex-page-toolbar`, page-level tabs, direct record actions, FOODEX components, authoritative master-data lookups, map-first geography, explicit loaded/empty/error/stale states, AR/EN and responsive behavior.

## Mobile applications

Customer, Driver and Van are separate Flutter apps with shared platform semantics but distinct permissions and workflows.

When changing shared contracts:

1. identify consuming apps;
2. evaluate all three for compatibility;
3. preserve role isolation;
4. authorize live records behind deep links/notifications;
5. add app-specific regression coverage where behavior differs.

## Data and finance

MySQL/MariaDB is authoritative persistent storage. Redis is cache/queue infrastructure, not the sole source of durable business truth.

For financial, order, assignment and settlement workflows:
- preserve immutable/auditable history;
- avoid deleting core ledger/order records as a side effect of UI cleanup;
- use transactions for multi-record transitions;
- make reconciliation semantics explicit;
- test cross-store / cross-tenant isolation.

## Integration boundaries

Treat payments, push, maps/geography and other external systems as failure-prone:
- validate callbacks/webhooks;
- authenticate/sign where supported;
- make processing idempotent;
- log correlation metadata without leaking secrets;
- distinguish transport failure from business rejection.

## Restaurant SaaS boundary

FOODEX Restaurant SaaS 2.0 is a modular vertical built on the existing SmartOrdering lineage and Enterprise Core; it is not a replacement platform.

Authoritative plan:
- `docs/execution/FOODEX_RESTAURANT_SAAS_MASTER_PLAN.md`
- `docs/execution/FOODEX_RESTAURANT_SAAS_GAP_MATRIX.json`
- umbrella #1572 / blueprint #1573

Durable invariants:
- reuse canonical FOODEX tenant/store, menu, order, payment, customer and SmartOrdering truth rather than creating V2 engines;
- model Organization -> Brand -> Location as a control-plane hierarchy mapped to existing authoritative entities;
- every optional restaurant module is entitlement-controlled, dependency-declared and safely disableable without deleting data or breaking unrelated modules;
- Restaurant SaaS is a B2C Retail Store client capability: an explicit `STORE:smart_ordering` enterprise entitlement is the master switch; missing/OFF hides operator navigation and fails closed across admin/public runtime while preserving restaurant data, and Super Admin manages the switch from Retail Store Provisioning rather than using Restaurant SaaS as a platform operating module;
- public restaurant/menu reads are versioned/cache-friendly and must respect the cPanel performance contract;
- SaaS commercial lifecycle (plans, subscriptions, trials, billing/dunning, onboarding and offboarding) is a first-class platform concern;
- restaurant operations modules (tables/reservations/KDS/workforce/inventory/integrations) consume shared Enterprise Core registry/entitlements/events/workflow/health contracts.


## Enterprise WMS boundary

Wave 3 WMS extends the existing warehouses, inventories, and stock_movements authority; it does not introduce a second stock ledger. Warehouse access remains subordinate to existing store RBAC and can be narrowed through explicit warehouse-user scopes. Transfers use a draft -> dispatched -> received lifecycle: dispatch decrements source inventory and writes the authoritative outbound stock movement, while receipt increments target inventory and writes the authoritative inbound movement. Retries are idempotent and transitions lock the affected records.

Inventory-count sessions snapshot authoritative inventory quantity, then lock and reconcile the same inventory rows on completion. A session is rejected as stale when inventory changed after the snapshot, preventing a physical count from overwriting legitimate concurrent movements. Transfer/count lists are bounded and indexed; WMS foundation services add no global request, scheduler, or provider work when unused.

Lot/batch metadata is warehouse/product-scoped and normalized, with optional manufacture/receipt/expiry dates and an explicit active/quarantined/closed lifecycle. Lot records never own stock quantities: `inventories` remains the balance authority and `stock_movements` remains the movement authority. Movement-to-lot provenance is additive metadata in `enterprise_inventory_lot_movements` with no quantity column, and expiry exception reads are warehouse-authorized, date-window bounded and indexed.

Stock reservations also remain inside the canonical inventory authority. A reservation is represented by `inventories.reserved_quantity` plus reference-scoped `reserve` / `release` (and consuming `sale`) provenance in `stock_movements`; there is no parallel reservation-balance ledger. Reservation changes lock the authoritative inventory row, reconcile the desired quantity for one reference, preserve unrelated reserved stock, reject over-allocation, and are retry-safe for the same desired state.

WMS reporting is a read-only composition over these same authorities. Inventory, lot/expiry, valuation/aging, and adjustment reports use warehouse-authorized bounded queries with cursor-style identifiers and canonical drill-down references. WMS does not own a reporting or export ledger: exports above the Enterprise threshold emit the shared queued/chunked export handoff contract so execution remains outside latency-sensitive inventory transactions and requester/store/warehouse scope can be revalidated by the Enterprise reporting consumer.

## Enterprise commercial truth boundary

Wave 6 convergence does not introduce replacement customer, order, pricing, credit, collection, or payment ledgers. `CustomerDomainResolver` remains the channel-aware customer identity authority; `orders` and `Order` remain order truth; `CommerceQuoteService` plus the existing B2B price resolver remain pricing truth; `B2bAccountLedgerService` remains credit/account truth; and `CollectionCustodyService` remains collection/remittance custody truth. Enterprise convergence layers may adapt and version these contracts, but they do not fork commercial state.

Commercial compatibility reads are resolved through server-authoritative store/channel scope. Event handoffs use an explicit versioned envelope and registry-owned event names so downstream CRM, Van, Collections, WhatsApp, Finance, and BI consumers can evolve without replacing the source records. Foundation code emits no new ledger and performs no background work by itself. B2B account-credit eligibility is a compatibility decision over `B2bAccountLedgerService` through `CommercialCreditEligibilityPolicy`; the policy may expose eligibility and shortfall, but it never owns or mutates credit state.

## Commercial Customer 360 composition

`CommercialCustomer360Service` composes read-only channel/store-scoped customer identity from the existing resolver/repository, bounded canonical order references, existing B2B ledger aggregates and optional WhatsApp conversation references. Reads never materialize customer identities or mutate source records. Each section enforces its own permission; disabled or unauthorized optional sections do not query their domain data, and unavailable sources have explicit states instead of fabricated fallback data. Wholesale identity needs an existing order/invoice/retail-wholesale store relationship before composition. No second customer, CRM or financial ledger is introduced.

## Release architecture

Mandatory contract: `docs/release/RELEASE_ARTIFACT_CONTRACT.md`.

One FOODEX release is a synchronized lineage. Customer, Driver and Van APKs, Dashboard update assets, manifests, build metadata and applicable Setup/fresh-install evidence must identify the intended same release source/version lineage.

A release may carry one mobile lineage forward only through an explicit
machine-readable freeze contract. The frozen app keeps its real source
version/build and tree identity in release metadata; it is never relabeled to
match the newer platform version. CI must prove the frozen tree has zero diff.

## Architecture change rule

If a task changes a boundary described here, update this file and add the reason to `DECISIONS.md` in the same PR.

## Finance source-module posting consumers

Finance consumes committed cross-module facts asynchronously through the existing versioned domain-event and transactional-outbox authority. Source modules never call Finance synchronously and never write Finance tables.

- Only real published source contracts are wired; future Sales/Inventory contracts plug into the same mapping layer without inventing event names early.
- A source event can post only while Finance is currently ENABLED and only when its event timestamp is on/after the current enable transition (state_changed_at). Events consumed while Finance is disabled or from before the current enable transition are not backfilled later.
- Posting requires explicit deployment configuration for ledger currency, distinct authorized maker/poster users, and debit/credit account codes. Missing or invalid mapping fails closed into the existing bounded outbox retry/dead-letter path.
- Every accounting mutation uses GeneralLedgerService::createDraft() and GeneralLedgerService::post() so fiscal-period, account, entitlement, RBAC and maker/checker/poster controls remain authoritative.
- Successful posting persists immutable source-event/version -> canonical journal provenance in enterprise_finance_source_postings; event identity and GL idempotency prevent duplicate posting.
- Source-scope mismatch, unsupported versions, Finance degradation, and mapping failures never roll back the already-committed source transaction.

## Agentic AI boundary

Authoritative contract: docs/architecture/FOODEX_AI_AGENT_ARCHITECTURE.md.

FOODEX has one Assistant lineage. Extend the existing Management Assistant controller/routes, conversation state, AssistantBrainInterface, AssistantToolRegistry, AssistantPageContext, runtime/provider settings and OpenAI-compatible/Ollama client. Do not build a parallel chatbot, direct model-to-database path or second tool framework.

Runtime direction is Dashboard Assistant -> server identity/RBAC/tenant/channel/retailer-consent -> agent orchestrator -> typed allowlisted tools -> deterministic semantic/read-model/domain services -> authoritative persistence. Providers may classify/plan/explain only; server software owns permissions, calculations and writes. Sell-in and sell-out are separately typed facts. Read-only remains default until #2224 adds draft -> explicit approval -> re-authorization -> idempotent execution.



### Agent orchestration invariant

The agent orchestrator is a bounded compatibility layer inside the existing Assistant request path. Provider output can propose only allowlisted typed read tools; every proposal is re-authorized and audited through `AssistantToolExecutor` before execution. Multi-tool plans have hard server-owned limits for steps, calls, wall time and model/result bytes, duplicate calls are suppressed, and only numbered server evidence reaches grounded synthesis. Planning text/reasoning is transient and is never persisted or exposed as chain-of-thought. Provider/no-tool/failure paths degrade back to the existing Assistant brain rather than breaking FOODEX.

### Deterministic analytics invariant

FOODEX diagnostic arithmetic is server-authoritative and versioned by `foodex.analytics.diagnostic.v1`. Equal-period comparisons, trend/velocity, mix/contribution, exception rates and inventory cover are computed in deterministic Laravel code. Missing, sparse, stale or incomplete evidence is surfaced explicitly; model providers may explain results but cannot override computed values or infer causation from correlation.

### Knowledge / RAG invariant

The Assistant knowledge layer is a governed read tool, not a second chatbot or data authority. Approved manuals, policies, procedures, SOPs, FAQs and product/business documents are indexed through `AssistantKnowledgeIndex` with exact version, locale and global-or-store scope. `knowledge.search` executes through the same `AssistantToolExecutor` authorization/audit boundary as every other tool. Inactive, revoked, deleted, superseded or expired sources cannot be retrieved.

Agent routing keeps live transactional questions on deterministic FOODEX tools and document questions on `knowledge.search`; hybrid turns may use both. Document excerpts are untrusted data: they are unavailable during planning, cannot select/enable tools, and synthesis has no callable tools. Weak retrieval evidence fails closed rather than inviting the provider to invent an answer. Knowledge-backed answers expose server-derived exact source/version citations and freshness metadata.

Authority: `docs/architecture/FOODEX_AI_KNOWLEDGE_RAG.md`, #2223.

### AI security guardrail invariant

The Assistant provider and typed-tool boundaries are wrapped by a server-owned security layer. User/provider/RAG text is never authority; prompt-injection and unsafe tool arguments fail closed, provider/tool/write layers have independent kill switches, tool results are minimized/redacted before becoming model evidence, and privacy-sensitive benchmark tools must enforce cohort minimum/differencing protection. These controls are additive to current RBAC, tenant/store/channel scope and retailer sell-out consent, and disabling AI must not disable ordinary FOODEX commerce. Authoritative threat model: `docs/security/FOODEX_AI_SECURITY_THREAT_MODEL.md`.
