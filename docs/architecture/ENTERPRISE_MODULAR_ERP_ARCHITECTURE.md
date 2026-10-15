# FOODEX Enterprise Modular ERP Architecture

Status: **Authoritative program charter**
Issue: #1482
Applies to: all Enterprise ERP modules and all work that changes shared platform contracts
Primary objective: grow FOODEX into an enterprise business platform without making `main` unstable, without coupling unrelated modules, and without introducing hosting requirements that break the supported cPanel deployment profile.

## 1. Non-negotiable invariants

1. `main` remains releasable while Enterprise ERP work is in progress.
2. A business module may not make unrelated FOODEX capabilities fail when the module is disabled, degraded, unavailable, upgrading, or externally blocked.
3. No module may be promoted to `main` until its exact-head regression, security, tenancy, migration/rollback and performance gates are green.
4. Module visibility is not authorization. Server-side policy remains authoritative.
5. Existing FOODEX tenancy, RBAC, release/update, scheduler and audit contracts are reused and extended; they are not replaced by parallel systems.
6. Existing production data is preserved. No Enterprise program task may depend on a database reset.
7. cPanel is a first-class runtime profile. New modules may use optional accelerators, but core correctness may not require a new persistent daemon, microservice or privileged host capability.
8. External integrations are side effects, never part of the atomic success condition of an unrelated core transaction.
9. Heavy analytics, exports, notification delivery and integration retries must not execute on latency-sensitive request paths.
10. Arabic RTL and English LTR are first-class enterprise product requirements, not post-release localization work.

## 2. Existing FOODEX contracts that remain authoritative

Enterprise work must build on:

- `docs/architecture/RBAC_ADMINISTRATION.md`
- `docs/architecture/MULTI_TENANT_ARCHITECTURE.md`
- `docs/features/ADMIN_NAVIGATION.md`
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
- `docs/design-reference/DASHBOARD_MOBILE_WEB_CONTRACT.md`
- `docs/quality/LOCALIZATION_CONTRACT.md`
- `docs/operations/PROMOTIONAL_NOTIFICATION_SCHEDULER.md`
- `docs/release/RELEASE_ARTIFACT_CONTRACT.md`
- repository-level `AGENTS.md`

If an Enterprise design conflicts with one of these authoritative contracts, the conflict must be resolved explicitly in the owning Issue before implementation.

## 3. Target architecture: modular monolith, not mandatory microservices

FOODEX remains one deployable product while enforcing module boundaries.

```text
FOODEX Enterprise Platform
|
+-- Core Kernel (cannot be disabled)
|   +-- Identity / Authentication
|   +-- Organization / Tenant / Store Context
|   +-- RBAC / Data Scope / Delegation
|   +-- Module Registry / Entitlements
|   +-- Audit
|   +-- Workflow / Approvals
|   +-- Settings / Master Data contracts
|   +-- Event / Outbox infrastructure
|   +-- Scheduler / Background execution contract
|   +-- Files / Attachments
|
+-- Business Modules (independently controllable)
|   +-- Sales
|   +-- Inventory / WMS
|   +-- Procurement
|   +-- Suppliers / AP
|   +-- Accounting / GL
|   +-- AR / Credit Control
|   +-- Treasury
|   +-- Distribution / Van Sales
|   +-- CRM
|   +-- WhatsApp
|   +-- Marketing
|   +-- Reporting / BI
|
+-- Integration Layer
    +-- Versioned business events
    +-- Adapters
    +-- Webhooks
    +-- Provider clients
    +-- Idempotent consumers
```

A module is a domain boundary, not merely a menu group.

## 4. Core Kernel

The following capabilities are platform services and may not be disabled:

- identity and authentication;
- tenant/store/organization resolution;
- effective permission evaluation;
- module registry and entitlement resolution;
- audit/event provenance;
- settings required to boot the application;
- workflow engine primitives;
- scheduler/background execution primitives;
- health and diagnostic primitives.

Business modules may depend on Core Kernel contracts. Core Kernel must never depend on an optional business module.

## 5. Module ownership contract

Each business module owns its domain code and persistence semantics. A target module layout should expose clear ownership for:

- routes;
- controllers;
- application services;
- domain services;
- models/repositories/query objects;
- policies/permissions;
- events/listeners;
- jobs;
- views/components;
- translations;
- migrations;
- tests;
- health checks;
- module manifest.

Shared files are exceptions and have one explicit owner per Issue.

### 5.1 Module manifest

Every Enterprise module must declare at least:

```yaml
code: procurement
version: 1
category: operations
default_state: DISABLED

required_core:
  - identity
  - tenant_context
  - rbac
  - audit
  - module_registry

hard_dependencies:
  - catalog

optional_integrations:
  - inventory
  - accounting
  - treasury
  - notifications

permissions:
  - procurement.view
  - procurement.po.create
  - procurement.po.approve

health_checks:
  - database
  - scheduler

navigation:
  group: operations
  order: 40
```

The concrete storage format may differ, but these semantics are mandatory.

## 6. Module lifecycle

Supported runtime states:

```text
NOT_INSTALLED
INSTALLED
DISABLED
ENABLING
ENABLED
SUSPENDED
MAINTENANCE
ERROR
```

A simple boolean is insufficient for operational diagnosis.

### 6.1 Disabled means safe

When a module is disabled:

- user navigation is hidden;
- public/admin routes return the canonical disabled/not-entitled response;
- scheduled jobs and event consumers for the module do not perform new business writes;
- background retries are paused or drained according to module policy;
- module data is preserved;
- audit/history is preserved;
- unrelated modules remain usable;
- application boot and migrations do not require third-party credentials for the disabled module.

Disabling a module never drops tables or deletes business history.

### 6.2 Activation

Activation must be explicit, auditable and reversible.

Stateful modules require an activation wizard with a declared effective date and initialization mode, for example:

- start from activation date;
- enter opening balances;
- import opening balances;
- explicitly rebuild selected history.

Historical rebuild is never an implicit side effect of enabling a module.

## 7. Dependency rules

### 7.1 Hard dependency

A hard dependency is allowed only when the dependent module cannot have correct business meaning without it.

Examples:

- inventory requires a catalog/product authority;
- sales line items require a sellable catalog authority.

The Module Registry must prevent disabling a hard dependency while active dependents require it.

### 7.2 Optional integration

Cross-domain enrichment is normally an optional integration, not a hard dependency.

Examples:

- Sales -> Accounting
- Procurement -> Accounting
- Sales -> WhatsApp
- Inventory -> BI

If Accounting is disabled, Sales remains valid.
If WhatsApp is unavailable, an Order remains valid.
If BI is unavailable, operational transactions remain valid.

## 8. Event and outbox boundary

Cross-module reactions must use explicit, versioned contracts.

Examples:

```text
OrderConfirmed.v1
GoodsReceived.v1
PaymentCollected.v1
InventoryAdjusted.v1
CustomerCreditChanged.v1
```

The producing transaction commits its own authoritative state and an outbox/event record atomically. Consumers process independently and idempotently.

Forbidden pattern:

```text
HTTP request
  -> save order
  -> call provider
  -> write accounting
  -> send notification
  -> recompute BI
  -> finally commit
```

Required pattern:

```text
HTTP request
  -> validate
  -> save authoritative business transaction
  -> save outbox event
  -> COMMIT
  -> return success
             |
             +-- asynchronous/bounded consumers
```

External network calls must not run inside database transactions.

## 9. Failure isolation

Every optional integration requires:

- bounded timeout;
- retry policy;
- idempotency key;
- failure visibility;
- dead-letter/manual retry path where appropriate;
- circuit-breaker or equivalent backoff for repeated provider failure;
- no rollback of an already-committed unrelated core transaction.

A degraded module reports `DEGRADED` or `ERROR`; it does not turn the whole platform unhealthy unless a Core Kernel invariant is affected.

## 10. Enterprise organization model

Target hierarchy:

```text
Platform
  -> Organization / Company
      -> Legal Entity
          -> Business Unit
              -> Branch / Store
                  -> Warehouse / Department / Cost Center
```

Not every installation must use every level, but scope resolution must support the hierarchy without duplicating users or permissions.

All business records must declare their ownership root and scope.

## 11. Authorization model

Authorization = permission + module entitlement + data scope + tenant/store scope + optional business limit.

Example:

```text
procurement.po.approve
+ organization A
+ branches 1,2
+ amount <= 5,000 KWD
+ procurement module enabled
```

The existing server-authoritative Gate/policy model remains authoritative.

### 11.1 Role scopes

Roles/assignments may be:

- platform/global;
- organization/company;
- legal entity;
- branch/store;
- warehouse/department where the business domain requires it.

### 11.2 Segregation of duties

Finance-sensitive workflows must support separation between:

- maker;
- checker;
- approver;
- poster;
- auditor.

A module may not hard-code “admin can do everything” as its only approval model.

### 11.3 Delegation

Temporary delegation must have:

- delegator;
- delegate;
- allowed scopes;
- start/end timestamps;
- audit entry;
- automatic expiry.

## 12. Shared approval/workflow engine

Modules must not build incompatible approval engines.

The shared engine evaluates:

- entity type;
- organization/branch;
- amount/risk;
- requester;
- required approval steps;
- approval limits;
- segregation-of-duties rules.

Initial consumers include:

- purchase requests/orders;
- vendor payments;
- refunds;
- stock adjustments;
- credit limit changes;
- journal posting;
- exceptional discounts;
- master-data changes where required.

## 13. Enterprise UX contract

Enterprise screens must use the existing FOODEX design contracts and shared primitives.

Required patterns include:

- Page Header;
- Breadcrumb;
- Action Bar;
- Filter Bar;
- Data Grid;
- status badges;
- Drawer/Modal/Wizard;
- Activity Timeline;
- Audit panel;
- Empty/Loading/Error states;
- Approval panel;
- Export/Print where allowed.

### 13.1 Record 360 pages

Primary business entities use consistent tabbed detail surfaces.

Examples:

```text
Customer
Summary | Orders | Finance | Payments | Contacts | Documents | Activity | Audit

Supplier
Summary | Purchases | Invoices | Payments | Documents | Activity | Audit

Product
Overview | Stock | Purchases | Sales | Costing | Pricing | Activity | Audit
```

### 13.2 Enterprise list screens

High-volume lists require:

- scoped search;
- server-side filters;
- bounded pagination;
- sortable indexed columns;
- column chooser where useful;
- saved views;
- compact bulk actions;
- export through bounded/queued paths;
- no unbounded “load all” control.

### 13.3 Role workspaces

Dashboards are role/action oriented, not one universal KPI wall.

Examples:

- Executive: revenue, margin, cash, AR/AP, stock value, exceptions.
- Finance: cash/bank, aging, unposted journals, approvals.
- Warehouse: low stock, expiries, count variance, transfers.
- Sales: sales, targets, returns, collections, customer risk.

## 14. Enterprise Action Center

A shared Action Center surfaces actionable exceptions such as:

- overdue invoices;
- low/reorder stock;
- pending approvals;
- stock discrepancies;
- exceeded credit limits;
- failed integration jobs;
- overdue follow-ups.

It links to exact authorized records. It must not duplicate business source-of-truth data.

## 15. Reporting and BI boundary

Operational transaction tables remain authoritative. Heavy analytics must move toward bounded read models/rollups as volume grows.

Interactive reporting must:

- require bounded filters/date ranges for high-volume datasets;
- use indexed dimensions;
- avoid per-row subqueries;
- expose drill-down to canonical records;
- queue large exports;
- separate report failure from transaction processing.

A generic report builder should model dimensions and measures instead of creating dozens of disconnected hard-coded report implementations.

## 16. Migration strategy

Enterprise migrations follow:

```text
EXPAND -> BACKFILL/MIGRATE -> SWITCH READS -> CONTRACT LATER
```

Rules:

- additive first;
- no destructive same-release schema change for production data;
- new columns are nullable/default-safe until all code can handle them;
- backfills are chunked and resumable;
- old and new application versions remain compatible during the declared rollout window;
- destructive cleanup requires a later Issue/release after evidence proves the old path is unused.

## 17. Mainline safety

A module implementation branch is isolated from `main` until its promotion gate is complete.

Incomplete module work may exist in an integration branch for as long as necessary while:

- normal fixes/hotfixes continue into `main`;
- normal releases continue from `main`;
- the module branch regularly syncs current `main`;
- no unrelated release waits for the module.

A module reaches `main` only when one of these is true:

1. **GA-ready promotion**: module is complete and all gates pass.
2. **Explicit dark-foundation promotion**: the change is completely unreachable by users, default-disabled, adds no mandatory runtime dependency, passes all regression/performance/migration gates, and the owning integration/certification Issue explicitly approves the dark merge.

There is no “partially visible” promotion state.

## 18. Performance is architecture

All Enterprise module work is subject to:

- `docs/quality/CPANEL_PERFORMANCE_CONTRACT.md`
- machine-readable gates in `docs/execution/FOODEX_ENTERPRISE_MODULE_GATES.json`

Performance failures are release blockers, not backlog polish.

## 19. Security baseline

Every module requires:

- deny-by-default permissions;
- tenant/data-scope tests;
- mass-assignment/input validation review;
- CSRF/auth review for web mutations;
- API rate limits where exposed;
- sensitive field/log review;
- audit coverage for privileged mutations;
- file validation for uploads;
- no provider secret leakage to page source/log exports;
- explicit support/admin bypass logging.

## 20. Module Definition of Done

A module is not complete until all applicable items are PASS:

- architecture boundary;
- data ownership;
- migrations/upgrade;
- disable/enable lifecycle;
- RBAC/data scope;
- approval/SoD where applicable;
- audit;
- API/domain behavior;
- UI/RTL/LTR/responsive;
- search/filter/pagination;
- reporting/export behavior;
- failure isolation;
- cPanel compatibility;
- performance budgets;
- security;
- regression;
- visual QA;
- operational runbook;
- rollback/recovery;
- exact-head CI;
- integration-captain certification.

No worker may declare a module complete while an applicable item is FAIL, UNKNOWN or unowned.
