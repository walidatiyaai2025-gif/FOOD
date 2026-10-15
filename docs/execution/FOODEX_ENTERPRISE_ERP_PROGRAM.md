# FOODEX Enterprise ERP Program Execution Plan

Status: **Authoritative execution plan**
Issue: #1482
Program rule: Enterprise work must not block normal FOODEX fixes, hotfixes or version releases.

## 1. Program outcome

Deliver an enterprise-grade FOODEX platform that can cover company administration, wholesale/retail/distribution, purchasing, inventory, finance, treasury, CRM, reporting and controlled approvals while preserving:

- current production behavior;
- existing tenant isolation;
- existing app/release contracts;
- cPanel performance;
- the ability to release normal fixes at any time.

## 2. Delivery model

The program uses **module integration branches**, not one giant long-lived ERP branch and not direct child merges to `main`.

For each major module:

```text
atomic child issue branches
        |
        v
feature/enterprise-<module>
        |
        | module integration + certification
        v
single promotion PR to main
```

Normal product fixes continue:

```text
fix/* or hotfix/*
        |
        v
      main
        |
        v
normal FOODEX releases
```

The Enterprise module branch regularly merges current `main` and proves compatibility before promotion.

## 3. No-blocking mainline policy

Enterprise work may not reserve/freeze `main`.

Rules:

1. existing production bugs/hotfixes keep their normal priority;
2. a red Enterprise branch never blocks an unrelated release;
3. no incomplete visible module UI is merged to `main`;
4. no migration from an unfinished module reaches `main` unless the dark-foundation exception is explicitly certified;
5. every module integration branch syncs current `main` before final certification;
6. final tests run on the exact promotion head after the last mainline sync;
7. after promotion, normal hotfix flow continues unchanged.

## 4. Promotion states

### 4.1 Integration-only

Module exists only on its integration branch. It may be incomplete.

### 4.2 Dark foundation

Optional, exceptional promotion of internal foundation only.

Allowed only when:

- no user-accessible route/navigation/action exists;
- state is default `DISABLED`;
- no new mandatory service/credential exists;
- boot with module disabled is proven;
- migrations are additive and data-safe;
- existing regression suite is green;
- performance gate is green;
- certification Issue explicitly approves the dark merge.

### 4.3 Pilot-ready

Module is functionally complete, disabled by default and can be enabled only for internal/pilot scope.

### 4.4 GA-ready

All certification is complete and explicit production activation is allowed.

## 5. Exact-head promotion gates

A module may not merge to `main` until every applicable gate below is PASS on the exact head.

### G0 — Ownership and convergence

- one integration Issue/captain;
- child Issue/PR ledger complete;
- no duplicate active implementation lane;
- branch synced with current main.

### G1 — Architecture boundary

- module manifest present;
- hard dependencies justified;
- optional integrations use contracts/events;
- disabled module boot path proven;
- no reverse Core -> optional-module dependency.

### G2 — Data/migration

- schema ownership documented;
- migrations additive first;
- production-like upgrade test passes;
- backfills chunk/resume;
- no destructive same-release migration;
- rollback/disable preserves data.

### G3 — RBAC / tenancy / scope

- permissions server-enforced;
- cross-tenant/store/branch denial tests;
- module entitlement enforcement;
- support/admin bypass audited;
- approval limits/scopes enforced where applicable.

### G4 — Functional

- canonical business journeys complete;
- no placeholder production paths;
- no dead CTA;
- user feedback truthful;
- idempotency for retryable operations.

### G5 — Enterprise UX

- FOODEX design contract;
- AR RTL + EN LTR;
- responsive;
- loading/empty/error;
- keyboard/accessibility contract;
- record/list conventions;
- visual evidence.

### G6 — Audit / workflow

- privileged mutations audited;
- approval rules covered;
- segregation of duties covered where applicable;
- delegation/override behavior explicit.

### G7 — Failure isolation

Prove:

- external provider outage;
- queue delay;
- scheduler missed run;
- module disabled;
- module degraded;

do not corrupt or block unrelated FOODEX core journeys.

### G8 — Performance / cPanel

Must satisfy `docs/quality/CPANEL_PERFORMANCE_CONTRACT.md`.

Required:

- seeded benchmark;
- p95/p99 comparison;
- query count;
- slow query evidence;
- memory;
- queue/scheduler evidence;
- disabled-module overhead;
- cPanel deployment check.

### G9 — Security

- auth/CSRF;
- validation/mass assignment;
- rate limiting where exposed;
- secret/PII log review;
- upload validation;
- privilege escalation tests;
- dependency/security checks.

### G10 — Regression

At minimum run relevant existing:

- Orders;
- Customers;
- Catalog;
- Inventory;
- B2B/B2C isolation;
- Finance where touched;
- WhatsApp/CRM where touched;
- Driver/Van/Customer apps where touched;
- navigation/localization;
- update bundle/deployment checks.

### G11 — Operational readiness

- health checks;
- metrics/logs;
- failed-job visibility;
- runbook;
- support diagnostics/System Inspector exposure;
- backup/recovery notes.

### G12 — Disable/re-enable

Prove:

```text
OFF -> boot/core journeys
ON  -> module journeys
ON -> OFF -> data preserved/core stable
OFF -> ON -> state restored/explicit activation rules honored
```

### G13 — Pilot

For material modules:

- internal enablement;
- selected pilot tenant/company;
- monitoring window;
- no P0/P1;
- no unacceptable performance regression;
- disable/emergency path tested.

### G14 — Final main promotion

- exact-head CI green;
- no unresolved P0/P1;
- integration captain signs certification;
- one final PR to `main`;
- module default state remains as approved by rollout policy.

## 6. Worker ownership model

Use atomic implementation Issues.

Recommended lane ownership:

- Platform Core owner;
- RBAC/Organization owner;
- UX/Design System owner;
- module domain owner(s);
- data/migration owner when complex;
- Reporting/BI owner;
- QA/Performance/Security certification owner;
- Integration Captain.

Shared files have one owner at a time.

High-conflict shared areas include:

- route registration;
- Admin navigation;
- permission catalog;
- module registry;
- translation roots;
- shared layout/design primitives;
- release metadata;
- migration ordering;
- scheduler registration.

Workers who need a shared contract wait for the owner’s change, then sync/rebase their remaining delta.

## 7. Program waves

### Wave 0 — Enterprise Core Platform

Goal: create the safe modular foundation without changing existing user behavior.

Scope:

- Module Registry;
- module states/lifecycle;
- entitlements/scopes;
- organization hierarchy extension;
- RBAC v2/data-scope primitives;
- shared approval/workflow primitives;
- versioned domain events;
- transactional outbox;
- module health;
- System Inspector module/performance diagnostics;
- compatibility hooks for current modules.

Exit condition:

- existing FOODEX behavior unchanged;
- disabled empty/sample module adds negligible overhead;
- cPanel scheduler/queue model proven;
- all existing critical regression suites green.

No business ERP module begins final integration until Wave 0 contracts are stable.

### Wave 1 — Enterprise Shell / Administration

Scope:

- Modules administration;
- Security Center extensions;
- organization/branch/business-unit administration;
- Action Center foundation;
- role-aware dashboards;
- Global Search foundation;
- saved list views;
- enterprise data-grid/filter patterns;
- system/module health surfaces.

Must reuse the existing FOODEX Dashboard design system.

### Wave 2 — Procurement + Suppliers

Scope:

- Supplier 360;
- supplier master/governance;
- Purchase Request;
- approval;
- Purchase Order;
- Goods Receipt;
- purchase invoice handoff;
- purchase return;
- supplier statements/aging foundation;
- documents/activity/audit.

Canonical flow:

```text
PR -> approval -> PO -> GRN -> purchase invoice -> payable/payment integration
```

### Wave 3 — Inventory / WMS Enterprise

Extend the existing inventory authority; do not create a parallel stock ledger.

Scope:

- multi-warehouse controls;
- transfers;
- counts/cycle counts;
- adjustments;
- damage/loss;
- batch/lot where applicable;
- expiry;
- reservations;
- reorder policy;
- valuation;
- aging/slow/dead stock;
- warehouse roles/scopes.

### Wave 4 — Finance: GL + AR + AP

Scope:

- Chart of Accounts;
- fiscal periods;
- journals;
- posting engine;
- cost centers;
- opening balances;
- AR;
- AP;
- credit terms/limits;
- aging;
- customer/supplier ledgers;
- posting integration contracts.

Sales/Procurement remain valid when Finance is disabled; Finance consumes their events only when enabled/activated.

### Wave 5 — Treasury

Scope:

- cashboxes;
- bank accounts;
- receipts;
- payments;
- cheques/PDC if required;
- bank reconciliation;
- daily cash closing;
- approval limits;
- treasury audit.

### Wave 6 — Commercial / Distribution convergence

Converge existing capabilities rather than rebuild them:

- Sales;
- CRM;
- Customers;
- pricing;
- credit control;
- Van Sales;
- distribution;
- collections;
- WhatsApp;
- delivery/field operations.

Objective: one commercial truth across channels with no duplicate order/customer ledgers.

### Wave 7 — Reporting / BI / Profitability

Scope:

- Report Builder;
- dimensions/measures;
- executive BI;
- profitability;
- cost/margin views;
- scheduled reports;
- bounded read models;
- export center;
- exception analytics;
- forecasting/recommendation only where deterministic authority is preserved.

## 8. Module issue topology

Each major module should have:

1. umbrella/module contract Issue;
2. data/domain Issue;
3. service/API Issue;
4. UI/UX Issue;
5. integration/events Issue;
6. reports/export Issue;
7. security/performance Issue;
8. integration/certification Issue.

Small modules may combine lanes when ownership remains clear.

The integration/certification Issue alone owns final promotion to `main`.

## 9. cPanel-first engineering rules

Every child Issue must answer before implementation:

- Does this need a persistent process? If yes, redesign unless it is optional.
- Can this work through the existing scheduler/queue contract?
- What is the largest table touched?
- What indexes support its common queries?
- What happens with 100k/500k rows?
- What work moves off the request path?
- What happens when Redis/provider/scheduler is unavailable?
- Does a disabled module execute any global query/job?
- What are the route query/memory budgets?
- How is a large export chunked?
- Can the operation resume after timeout?

## 10. Release compatibility

Enterprise module work must support normal version production cadence.

Before each final module promotion:

1. merge latest `main` into module integration branch;
2. resolve contracts without reverting newer main fixes;
3. rerun full exact-head gate;
4. generate/update required release artifacts only when the normal FOODEX release process calls for them;
5. never hold an unrelated version release waiting for Enterprise work.

If a production hotfix lands after certification but before merge, certification is stale and must be rerun after sync.

## 11. Migration safety during active releases

Because main continues moving:

- module migrations use unique ordered timestamps;
- never edit an already-released migration;
- backfills are separate resumable commands/jobs when large;
- new code tolerates partially backfilled rows during the rollout window;
- old code remains safe against additive schema;
- contract/drop cleanup is deferred to a later release.

## 12. Performance integration captain responsibilities

The certification owner must reject promotion when:

- benchmark evidence is absent;
- benchmark dataset is trivial;
- query regression is unexplained;
- cPanel worker model depends on an unavailable daemon;
- one module causes global boot/dashboard overhead when disabled;
- large reports/exports are synchronous;
- module failure degrades unrelated routes;
- database indexes are not proven for critical access paths.

## 13. Pilot and rollout policy

Default rollout:

```text
DISABLED
  -> INTERNAL
  -> PILOT TENANT(S)
  -> LIMITED
  -> GA
```

Activation is explicit and auditable.

A pilot must capture:

- error rate;
- p95/p99;
- DB load;
- queue lag;
- failed jobs;
- business reconciliation mismatches;
- support incidents;
- rollback/disable outcome.

## 14. Rollback model

Preferred rollback order:

1. disable/suspend the affected module;
2. stop its consumers/jobs;
3. preserve data and audit;
4. restore core service health;
5. investigate/replay idempotent work;
6. code rollback only when required.

A module rollback must not require reverting unrelated FOODEX hotfixes/releases.

## 15. Enterprise release definition

An Enterprise module is releasable only when:

- module certification gates G0-G14 pass;
- exact-head Required CI/repository policy are green;
- cPanel profile is green;
- no unresolved P0/P1;
- documented module state after deployment is explicit;
- support can diagnose it through System Inspector/health surfaces;
- disabling it is proven safe.

## 16. Success criteria for the whole program

The program is successful when FOODEX can be sold/configured as one codebase at multiple capability levels, for example:

```text
Commercial:
Sales + Inventory + WhatsApp

Distribution:
Sales + Inventory + CRM + Van + Procurement

Enterprise:
Full ERP + GL/AR/AP + Treasury + WMS + Distribution + BI
```

without forks, duplicated business ledgers, or one customer’s disabled modules imposing material runtime cost on another deployment.
