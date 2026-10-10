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

## Release architecture

Mandatory contract: `docs/release/RELEASE_ARTIFACT_CONTRACT.md`.

One FOODEX release is a synchronized lineage. Customer, Driver and Van APKs, Dashboard update assets, manifests, build metadata and applicable Setup/fresh-install evidence must identify the intended same release source/version lineage.

## Architecture change rule

If a task changes a boundary described here, update this file and add the reason to `DECISIONS.md` in the same PR.
