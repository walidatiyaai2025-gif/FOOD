# V2 #2825 — Customer Service vs. General Ledger boundary (T087R05)

**Integration:** `Foodex_V2` only. **Parent:** #2503. **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, T087R05.

## Authoritative behavior

Customer Service Manager, Supervisor, Agent, legacy Customer Support and Retail Customer Support must not inherit General Ledger permissions. The canonical built-in baseline is `backend/config/permissions.php` (`roles`); `BuiltInJobRoleCatalog::defaultPermissions()` reconciles these baselines into the database, preserving custom-role records and separately governed user overrides. None of these service roles gets any `finance.*` permission by default. Operational `orders.view` and customer-support permissions remain intact.

The existing Dashboard Finance / General Ledger workspace lives under `admin.erp.finance.index` with journal tab `tab=journals`; journal writes use `admin.erp.finance.journals.store`. Server-side access uses actual `finance.view/manage/post/approve`, scoped enterprise data authority and module entitlement/readiness. `AdminNavigation::routeItemEnterpriseModule()` hides the Finance leaf when the effective permission is missing. Menu visibility alone is not an authorization control.

A separate legitimate Finance role is permitted to retain finance access. This source-example does **not** impose a blanket denial on a properly authorized user with an independent Finance role or valid scoped override; cross-role, maker/checker and user-override policy are handled by their own V2 work items.

## Test and negative-path evidence

Run `cd backend && php artisan test --filter=V2CustomerServiceJournalAccessTest` and the repository Required CI on the **exact PR head**.

- All five service role definitions, seeded permission pivots and canonical legacy permission lists exclude `finance.*`.
- In an **ENABLED** and platform-entitled Finance module, service-only global roles cannot open the journal/financial-statements tabs or POST a journal with a real wholesale store context: HTTP **403** in both Arabic and English.
- Service-only Sidebar projections contain no `erp_finance` destination; a properly authorized Finance role retains `finance.view`.
- Reconciliation removes an injected stale `finance.view` pivot while retaining `orders.view`; forbidden writes never create journal rows.

## Cross-application impact

- **Dashboard:** tested navigation projection, direct GET/POST authorization, bilingual request handling. No new visual component, route, form, label, translation or layout.
- **Customer Flutter:** no changed API contract, payload, session, UI, or permission consumed by this app.
- **Driver Flutter:** no changed API, assignment, financial collection, or delivery contract.
- **Van Flutter:** no changed API, invoice, cash-collection, or Van fulfillment contract.

No mobile change issue is required for this test-only certification of existing Dashboard authorization. No schema migration, production data reset, seed-data mutation, installer, release, main-branch change, or deployment is involved. Runtime/staging visual acceptance and exact-head CI remain separate gates before issue closure.
