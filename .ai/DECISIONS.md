# FOODEX Durable Decisions

This is a concise decision log for choices that should survive individual chats/tasks. It does not replace detailed contracts under `docs/`.

## D-001 — Live repository state outranks cached context
**Decision:** GitHub Issue/branch/PR/exact-head CI is authoritative for execution state. Chat history, `.ai/CURRENT_STATE.md`, mission registries and handoff summaries are orientation aids.

## D-002 — One atomic task owns one branch and one PR
**Decision:** Resume the same Issue/branch/PR across workers and sessions. Disconnects or red CI do not justify replacement branches.

## D-003 — Business state transitions stay server-authoritative
**Decision:** Order, finance, assignment, delivery and similar transitions are validated and authorized in the backend. Clients request transitions; they do not become the source of truth.

## D-004 — UI is business-first, not database-first
**Decision:** Dashboard uses business-domain navigation, meaningful lookups/builders and direct record actions. Internal IDs/raw JSON are not normal business inputs.
**Authority:** `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`.

## D-005 — Localization is correctness
**Decision:** AR/EN parity, RTL/LTR and localized business values are product correctness requirements.
**Authority:** `docs/quality/LOCALIZATION_CONTRACT.md` and `AGENTS.md`.

## D-006 — Release artifacts share one lineage
**Decision:** Dashboard update assets plus Customer/Driver/Van artifacts and relevant Setup/fresh-install evidence must agree on release version/source lineage.
**Authority:** `docs/release/RELEASE_ARTIFACT_CONTRACT.md`.

## D-007 — Green CI is necessary but not universal product proof
**Decision:** Visual/interaction requirements require matching runtime evidence where promised. Closed Issue + generic green CI cannot substitute.

## D-008 — Repository-native AI context stays thin
**Decision:** `.ai/` summarizes durable knowledge and reusable playbooks while referencing authoritative contracts rather than copying them wholesale.

## Adding a decision
Append decision, Issue/PR/date when relevant, reason, consequences and authoritative contract. Do not add trivial implementation notes.


## D-009 — Wholesale fulfillment belongs to Van; Retail fulfillment belongs to Driver
**Decision (owner-approved target, migration #1190):** Fulfillment actor is determined by order channel, not order source. All `b2b`/Wholesale orders are routed, delivered, collected/proved and operationally owned by Van. All `b2c`/Retail orders remain Driver-owned. Customer App, Dashboard and Van-created B2B orders all enter the same Smart-Routing -> Van fulfillment path. Unresolved B2B routing enters an explicit Van dispatch exception and must never fall back to Driver.
**Current-runtime caution:** this is the target under execution; existing source still contains historical mixed Driver/Van B2B behavior until #1190 closes.
**Authority:** `docs/execution/FOODEX_B2B_VAN_FULFILLMENT_EXECUTION_PLAN.md` and umbrella #1190.


## D-010 — Restaurant SaaS extends canonical FOODEX domains; it does not create parallel V2 engines
**Decision (program #1572 / blueprint #1573):** FOODEX Restaurant SaaS 2.0 is a modular restaurant operating-platform vertical built on the existing SmartOrdering and Enterprise Core contracts. Organization/Brand/Location is a control-plane hierarchy mapped to existing authoritative tenant/store/site entities. Menu, order, payment, customer, entitlements, events/workflow and module health remain canonical shared domains rather than being reimplemented for the SaaS vertical. Optional restaurant capabilities are independently entitled and must degrade safely when disabled.
**Reason:** the delivered Smart Ordering baseline already owns first-party menu/order/growth flows, while Enterprise Core is establishing generic modularity. A second set of restaurant engines would create drift, cross-tenant risk, duplicate pricing/order truth and a migration burden.
**Consequences:** new Restaurant SaaS work must begin with a reuse/gap audit; net-new modules integrate through stable domain contracts; plan/subscription state resolves to entitlements instead of plan-name conditionals; public reads use revisioned/cache-friendly projections; module OFF states preserve data and cannot break unrelated modules.
**Authority:** `docs/execution/FOODEX_RESTAURANT_SAAS_MASTER_PLAN.md`, `docs/execution/FOODEX_RESTAURANT_SAAS_GAP_MATRIX.json`, #1572 and #1573.

## D-011 — Enterprise WMS extends the canonical inventory ledger
**Decision (Wave 3 / #1728):** Enterprise WMS uses the existing `warehouses`, `inventories`, and `stock_movements` records as the authoritative stock ledger. Warehouse-user scope may narrow existing store RBAC but never bypass it. Transfers use a locked, idempotent draft -> dispatched -> received lifecycle whose outbound and inbound effects are written to `stock_movements`. Cycle counts snapshot authoritative inventory and refuse stale completion when stock changed after the snapshot.

**Reason:** a parallel WMS balance ledger would split stock authority, create reconciliation races with Orders/Van/Smart Ordering, and make retry/concurrency behavior unsafe. Preserving the existing ledger allows Wave 3 workflows to add control without changing current inventory ownership.

**Consequences:** transfer/count tables hold workflow state and provenance only; stock balances remain in `inventories`; all quantity effects require canonical `stock_movements`; list paths stay bounded/indexed; no daemon/provider/global request work is introduced by the foundation.

**Authority:** #1491, #1728, `docs/execution/FOODEX_ENTERPRISE_ERP_PROGRAM.md`, and `docs/quality/CPANEL_PERFORMANCE_CONTRACT.md`.

## D-012 — Stock reservations stay in inventories + stock_movements
**Decision (Wave 3 / #1802):** WMS stock reservations use the existing `inventories.reserved_quantity` as the authoritative reserved balance and reference-scoped `reserve` / `release` / consuming `sale` provenance in `stock_movements`. No separate reservation-balance ledger is introduced.

**Reason:** reservation availability already feeds Orders, Smart Ordering, reorder policy and valuation through `quantity - reserved_quantity`. A second reservation balance would create reconciliation races and conflicting availability truth.

**Consequences:** reservation mutation must lock the inventory row, preserve reservations owned by other references, reject over-allocation, reconcile retries idempotently to the desired reference quantity, and keep reference lookups indexed on the high-growth stock-movement table. Adjustments, damage and loss remain separate WMS children.

**Authority:** #1491, #1802, `docs/execution/FOODEX_ENTERPRISE_ERP_PROGRAM.md`, and `docs/quality/CPANEL_PERFORMANCE_CONTRACT.md`.

## D-013 — WMS reporting composes canonical inventory truth and delegates large-export execution
**Decision (Wave 3 / #1807):** WMS reporting is read-only over canonical `inventories`, `stock_movements`, lot/expiry metadata and valuation policy. Interactive reads are warehouse-authorized and bounded; deterministic drill-down references point back to canonical inventory, lot and movement records. WMS does not create a reporting/export ledger.

**Reason:** duplicating stock/reporting truth or executing large result sets on inventory request paths would introduce reconciliation drift and cPanel latency risk. Wave 7 already defines the Enterprise queued/chunked export contract.

**Consequences:** exports above 5,000 rows emit the shared Enterprise export handoff with requester/store/warehouse scope and resumable chunk metadata; the Enterprise reporting consumer owns durable export execution. Report/export failure cannot mutate or block inventory transactions, and unchanged Customer/Driver/Van applications remain outside this backend-only lane.

**Authority:** #1491, #1807, #1732, #1800, `docs/execution/FOODEX_ENTERPRISE_ERP_PROGRAM.md`, and `docs/quality/CPANEL_PERFORMANCE_CONTRACT.md`.

## D-014 — Commercial convergence adapts existing truth; it does not fork ledgers
**Decision (Wave 6 / #1731):** customer identity, orders, pricing, B2B credit/account balance, and collection/custody keep their existing FOODEX authorities. Convergence code may expose compatibility readers, authority metadata, and versioned event handoffs, but it must not create OrdersV2, CustomerV2, a second payment/credit ledger, or client-authoritative pricing.

**Reason:** Sales, B2B/B2C, Van, Collections, CRM, and WhatsApp already share production state. Replacing those records during convergence would split authority, invalidate existing mobile flows, and make financial reconciliation ambiguous.

**Consequences:** `CustomerDomainResolver`, `Order`/`orders`, `CommerceQuoteService`, `B2bAccountLedgerService`, and `CollectionCustodyService` remain canonical. `CommercialCreditEligibilityPolicy` is only a deterministic decision adapter over the existing B2B ledger summary and cannot become a credit ledger or mutate account state. Cross-module consumers use server-scoped compatibility contracts and event version 1; visible flow rewrites and new posting ledgers are outside this foundation.

**Authority:** #1494, #1731, `docs/architecture/ENTERPRISE_MODULAR_ERP_ARCHITECTURE.md`, and `docs/execution/FOODEX_ENTERPRISE_ERP_PROGRAM.md`.

## Commercial Customer 360 read composition

Compose existing channel-specific customer identities and source records through a read-only adapter. Keep store/channel and per-section permissions explicit, use bounded order/reference reads and the existing ledger aggregate contract, and expose missing/disabled/unavailable source states. Identity reads must never call materializing customer resolver methods. No registry or WhatsApp authority changes are required for this foundation while those files have active owners.

## D-015 — Agentic AI extends the existing FOODEX Assistant and never becomes a data authority
**Decision (#2209 / AI-01, 2026-10-18):** The AI-PROGRAM evolves the current Management Assistant in place. Existing Assistant routes/conversations, AssistantBrainInterface, AssistantToolRegistry, page-context/runtime settings and the OpenAI-compatible/Ollama provider lineage are the only integration roots. Providers are reasoning adapters, not authorization, calculation, persistence or execution authorities.

**Reason:** the current Assistant already has read-only tenant/store/channel safeguards, deterministic tools, persistent conversation state and a working Ollama/Qwen-compatible route. A parallel agent/chat stack would duplicate policy and state, increase cross-tenant risk and split business truth.

**Consequences:** every tool/action is server-authorized; models get no database credentials or generic SQL capability; deterministic services calculate business metrics; sell-in and sell-out remain separate fact classes; provider failure degrades safely; writes stay disabled until the explicit #2224 approval lifecycle exists.

**Authority:** docs/architecture/FOODEX_AI_AGENT_ARCHITECTURE.md, #2208 and #2209.

## D-016 — AI sell-out access is exact, revocable and re-authorized per call
**Decision (#2211 / AI-03, 2026-10-18):** Retail sell-out remains retailer-owned protected data. Wholesale Assistant access is allowed only for the canonical FOODEX Wholesale Principal, an exact Retail store relationship, an active purpose-bound grant, and current FOODEX permissions. Grant/revoke requires `assistant.sellout.share`; every tool execution rebuilds principal/store/channel authority server-side.

**Reason:** page context, conversation memory, provider output and a historical grant must never become authorization. A broad B2B role or sell-in relationship is insufficient evidence that Retail sell-out may be analyzed.

**Consequences:** revocation blocks subsequent reads immediately; multi-retailer aggregation authorizes every Retail store independently; consent/read/tool access is audited; localized denial does not expose hidden ids; providers receive no authorization power.

**Authority:** `docs/architecture/FOODEX_AI_SECURITY_CONTRACT.md`, `docs/architecture/FOODEX_AI_AGENT_ARCHITECTURE.md`, #2208 and #2211.


## D-017 — Retail sell-out ingestion accepts actual sales only
**Decision (#2217 / AI-09, 2026-10-18):** Canonical sell-out is composed only from actual retailer-to-customer transactions: native FOODEX B2C orders plus explicitly connected external POS/API/CSV sell-out events. Retail replenishment and wholesale purchases remain separate sell-in/procurement facts and are never inferred, relabelled or substituted as sell-out.

**Reason:** purchase-to-retail timing, stock holding, returns and unsold inventory make wholesale replenishment an invalid proxy for consumer demand. AI decisions must distinguish absent/partial retail feeds from genuine zero sales.

**Consequences:** external rows are tenant-owned, SKU-mapped, timezone-normalized, idempotent and reconciled; rejected rows are reported without persisting raw rejected payloads; active retailer consent is re-authorized on analytical reads; feed state exposes no/partial/available coverage and quality; the deterministic analytics layer combines actual sources while the LLM remains an interpreter only.

**Authority:** `docs/architecture/FOODEX_AI_RETAIL_SELLOUT_INGESTION.md`, `docs/architecture/FOODEX_AI_ANALYTICS_READ_MODEL.md`, #2208 and #2217.

## D-018 — Agent planning is bounded; server evidence is the only synthesis authority
**Decision (#2215 / AI-07, 2026-10-18):** Multi-tool Assistant turns use the existing provider gateway only to propose allowlisted typed reads and synthesize from numbered server evidence. `AssistantToolExecutor` remains the mandatory authorization/audit gate for every proposed call. The orchestrator enforces hard step, call, time and result-size budgets, suppresses duplicate tool signatures and never persists/exposes provider reasoning.

**Reason:** an unconstrained agent loop could turn model output into authority, amplify accidental repeated reads, leak scope through tool arguments or create unbounded latency/cost. FOODEX already has authoritative typed tools, semantic metrics, read models and security controls.

**Consequences:** unknown/malformed tools fail closed; authorization/consent/schema failures are never converted into permissive fallbacks; provider/tool outages may yield explicit partial answers; AI-disabled/no-tool/provider-failure paths fall back to the existing Assistant; no chain-of-thought is stored; no public API contract changes are introduced by orchestration.

**Authority:** `docs/architecture/FOODEX_AI_AGENT_ORCHESTRATOR.md`, `docs/architecture/FOODEX_AI_AGENT_ARCHITECTURE.md`, #2208 and #2215.

## D-019 — AI diagnostics are deterministic evidence, not model arithmetic or causation
**Decision (#2218 / AI-10, 2026-10-18):** FOODEX period comparisons, trend/velocity, mix/contribution, refund/cancellation rates, inventory cover and margin diagnostics are computed by versioned server-side software before model narration. The reusable result contract is `foodex.analytics.diagnostic.v1`; model providers may explain it but cannot replace its values, submit SQL or promote correlation into a causal claim.

**Reason:** business diagnostics must remain reproducible across Arabic/English narration, model/provider changes and provider outages. Missing comparison periods, sparse samples, stale/incomplete facts, zero denominators and incompatible currencies must produce explicit evidence limitations rather than invented percentages or causes.

**Consequences:** `analytics.diagnose` reuses the #2213 authorized read model, independently authorizes the prior period, preserves live sell-out consent and retention boundaries, gates SKU/category/margin detail behind catalog permission, and returns machine-readable confidence/quality reasons. Missing prior evidence suppresses comparative breakdowns rather than treating absence as zero.

**Authority:** `docs/architecture/FOODEX_AI_DETERMINISTIC_ANALYTICS.md`, `docs/architecture/FOODEX_AI_TOOL_REGISTRY.md`, #2208 and #2218.

## D-020 — RAG documents are governed evidence, never instructions
**Decision (#2223 / AI-15, 2026-10-18):** Approved FOODEX document knowledge is exposed to the existing Assistant only through the typed `knowledge.search` read tool. Sources carry exact version, locale and global-or-store scope; superseded, revoked, deleted and expired sources are excluded. Weak retrieval evidence is refused. Document excerpts are always untrusted data and cannot authorize, select or execute tools.

**Reason:** mixing document text with agent instructions would allow indirect prompt injection, while allowing unscoped or stale documents would create cross-tenant and grounding failures. Live business facts also have different freshness/authority semantics from policy/manual content.

**Consequences:** planning routes live facts to deterministic domain/read-model tools and documents to `knowledge.search`; hybrid questions may use both. Planning never sees retrieved document text, synthesis has no callable tools, every knowledge read is re-authorized/audited through `AssistantToolExecutor`, and final knowledge answers append server-derived exact source/version citations. Provider-disabled behavior remains additive/degraded and cannot take core FOODEX down.

**Authority:** `docs/architecture/FOODEX_AI_KNOWLEDGE_RAG.md`, `docs/architecture/FOODEX_AI_AGENT_ARCHITECTURE.md`, #2208 and #2223.

## D-021 — Assistant memory carries context, never transactional truth
**Decision (#2216 / AI-08, 2026-10-18):** FOODEX reuses the existing Assistant conversation/state tables and adds a bounded session policy. Server-resolved page context may travel in a short-lived HMAC-authenticated envelope bound to the authenticated user, but the envelope is not authorization: every turn re-runs page visibility and AI principal/RBAC/tenant/channel/retailer-consent resolution. Durable Assistant memory is limited to allowlisted presentation preferences; short conversation state contains identifiers/windows needed for follow-ups only.

**Reason:** browser IDs and chat history are stale and attacker-controlled inputs if treated as authority. Keeping business values or permission snapshots in memory could answer from obsolete revenue/stock/sell-out facts or survive access revocation.

**Consequences:** forged or cross-user context tokens fail closed; expired session state is discarded; secrets are redacted before message persistence; follow-ups may reuse only still-authorized entity/window identifiers; transactional facts are freshly read from typed tools/read models; provider-disabled FOODEX keeps its deterministic Assistant path; no second chat database and no public `/api/v1` contract are introduced.

**Authority:** `docs/architecture/FOODEX_AI_CONTEXT_MEMORY.md`, `docs/architecture/FOODEX_AI_SECURITY_CONTRACT.md`, #2208 and #2216.

## D-022 — AI security is enforced at provider and tool boundaries, not by prompt trust
**Decision (#2229 / AI-20, 2026-10-19):** FOODEX treats model/user/RAG text as untrusted and enforces AI-specific security in server code at the outbound provider boundary and the existing `AssistantToolExecutor`. The security layer owns prompt-injection blocking, output/secret filtering, tool/write kill switches, unsafe argument rejection, result minimization and cohort/differencing privacy protection.

**Reason:** prompt wording alone cannot guarantee isolation against malicious user text, poisoned documents, compromised model output or approval spoofing. Controls must remain authoritative even when the model ignores instructions or AI is disabled.

**Consequences:** provider failures/injection degrade to the deterministic Assistant where safe; tool/tenant/consent denial never widens scope; writes remain disabled by default; protected benchmarks require server-emitted privacy metadata; security events record bounded reasons without raw malicious prompts/secrets; no public API contract changes.

**Authority:** `docs/security/FOODEX_AI_SECURITY_THREAT_MODEL.md`, `docs/architecture/FOODEX_AI_SECURITY_CONTRACT.md`, #2208 and #2229.
