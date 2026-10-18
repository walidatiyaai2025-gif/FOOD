# FOODEX System Architecture

FOODEX is one platform with one Laravel backend/API/admin web application and exactly two Flutter applications.

## Runtime boundaries

1. Laravel is the single source of truth for authentication, authorization, store scoping, pricing, catalog, inventory, orders, delivery, payments, reporting, installer/updater state and mobile APIs.
2. The Management Web Dashboard is one role-based Laravel web system. Do not create duplicate B2B/B2C admin applications.
3. The Customer App is one Flutter binary for B2C Guest, B2C Customer and approved B2B Customer experiences.
4. The Driver App is one Flutter binary for B2C_DRIVER and B2B_DRIVER; authorization and assignment queries must keep the channels separate.
5. MySQL/MariaDB is the production relational database for the current cPanel deployment. Cache/queue backends are environment-configurable; backend business rules must not depend on a specific cache driver.
6. REST /api/v1 is the public application contract; OpenAPI is canonical.

## Dependency direction

Web/Flutter UI -> controllers/requests -> policies -> domain actions/services -> models/persistence -> PostgreSQL/Redis.

Mobile clients may own presentation state only. Backend-owned business rules must not be duplicated in Flutter.

## Scoping and audit

The authoritative tenancy contract is `docs/architecture/MULTI_TENANT_ARCHITECTURE.md`.

- The platform owner is the wholesale/B2B principal.
- Every B2C retail store is an isolated tenant.
- Every store-dependent query is scoped through the authoritative StoreContext/TenantContext.
- B2C Store Admin sees only explicitly assigned stores.
- B2B wholesale and B2C retail customer/catalog/operational domains never cross.
- B2C and B2B driver assignments never cross.
- Critical mutations require authorization, ownership validation and audit entries.
- SUPER_ADMIN support access across stores is explicit/auditable; unscoped application queries are prohibited.

## Bootstrap scope

Only application roots, domain boundaries, contracts and infrastructure foundations are established now. Product workflows remain issue-scoped implementation work.

## Application Preview runtime parity

The authoritative application-preview contract is `docs/architecture/APP_PREVIEW_ARCHITECTURE.md`.

- Dashboard Preview is not a third application implementation. It must embed/reuse the Customer/Driver Flutter runtime or shared presentation packages and the same backend business contracts.
- Laravel remains authoritative for preview authorization, StoreContext, pricing, catalog, orders, delivery state and app-visible capabilities.
- Preview-specific APIs are limited to secure preview-session/revision resolution or equivalent context plumbing; they must not duplicate Customer/Driver business APIs.
- Guest/authenticated, B2B/B2C, Retail store, locale, Draft/Published and runtime-version context must be explicit and testable.
- Preview credentials are short-lived, scoped, auditable and invalid as normal production app login credentials.
- A Dashboard-managed app-visible change is incomplete unless the same PR preserves real-app/shared-runtime preview parity and updates contract/regression coverage.

## Agentic AI / Assistant boundary

The authoritative agent architecture is docs/architecture/FOODEX_AI_AGENT_ARCHITECTURE.md.

FOODEX Agentic AI extends the existing Management Assistant; it is not a second application or chatbot stack. The existing Assistant HTTP/controller/conversation state, AssistantBrainInterface compatibility seam, typed tool registry, page context, runtime settings and OpenAI-compatible/Ollama provider lineage are the integration roots.

The dependency direction is:

**Dashboard Assistant -> server identity/policy/tenant/consent -> agent orchestrator -> typed allowlisted tools -> deterministic semantic/read-model/domain services -> authoritative persistence.**

The provider is a reasoning adapter only. It receives no database credentials, cannot execute SQL or arbitrary code, cannot grant scope and cannot authoritatively calculate business metrics. Sell-in and sell-out remain distinct fact classes. Every tool/action is re-authorized server-side; future writes require the explicit approval contract owned by AI-16/#2224. Provider or Assistant failure must not make core FOODEX commerce unavailable.

