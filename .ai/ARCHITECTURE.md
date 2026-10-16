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

Key implications: business-domain navigation, page-level tabs, direct record actions, FOODEX components, authoritative master-data lookups, map-first geography, explicit loaded/empty/error/stale states, AR/EN and responsive behavior.

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
- public restaurant/menu reads are versioned/cache-friendly and must respect the cPanel performance contract;
- SaaS commercial lifecycle (plans, subscriptions, trials, billing/dunning, onboarding and offboarding) is a first-class platform concern;
- restaurant operations modules (tables/reservations/KDS/workforce/inventory/integrations) consume shared Enterprise Core registry/entitlements/events/workflow/health contracts.


## Enterprise commercial truth boundary

Wave 6 convergence does not introduce replacement customer, order, pricing, credit, collection, or payment ledgers. `CustomerDomainResolver` remains the channel-aware customer identity authority; `orders` and `Order` remain order truth; `CommerceQuoteService` plus the existing B2B price resolver remain pricing truth; `B2bAccountLedgerService` remains credit/account truth; and `CollectionCustodyService` remains collection/remittance custody truth. Enterprise convergence layers may adapt and version these contracts, but they do not fork commercial state.

Commercial compatibility reads are resolved through server-authoritative store/channel scope. Event handoffs use an explicit versioned envelope and registry-owned event names so downstream CRM, Van, Collections, WhatsApp, Finance, and BI consumers can evolve without replacing the source records. Foundation code emits no new ledger and performs no background work by itself. B2B account-credit eligibility is a compatibility decision over `B2bAccountLedgerService` through `CommercialCreditEligibilityPolicy`; the policy may expose eligibility and shortfall, but it never owns or mutates credit state.

## Release architecture

Mandatory contract: `docs/release/RELEASE_ARTIFACT_CONTRACT.md`.

One FOODEX release is a synchronized lineage. Customer, Driver and Van APKs, Dashboard update assets, manifests, build metadata and applicable Setup/fresh-install evidence must identify the intended same release source/version lineage.

A release may carry one mobile lineage forward only through an explicit
machine-readable freeze contract. The frozen app keeps its real source
version/build and tree identity in release metadata; it is never relabeled to
match the newer platform version. CI must prove the frozen tree has zero diff.

## Architecture change rule

If a task changes a boundary described here, update this file and add the reason to `DECISIONS.md` in the same PR.
