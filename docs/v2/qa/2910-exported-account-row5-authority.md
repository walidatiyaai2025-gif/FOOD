# V2 #2910 — historical Table 5 row 5 / B2B Driver authorization boundary

## Primary source and confidentiality

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, **native Table 5, data row 5**; umbrella #2503; integration target `Foodex_V2` only.
- Historical classification: one globally assigned `B2B_DRIVER` role and **one distinct inherited permission** at export time. This is historical provenance, **not** evidence of the current account's activity, profile, assignments, store or effective access.
- The exported account's identity, address, identifiers, contact fields and secrets are deliberately excluded from source, tests, PR and CI. Tests use fictional fixtures on a disposable database. No current/live account was inspected or changed.
- This is **not** the Retail `B2C_DRIVER` account from row 3 (#2908). It also must not be conflated with the different historical B2B Driver entries in rows 6–8.

## Canonical runtime authority and regression

Existing `config/job_roles.php` defines `B2B_DRIVER` with `deliveries.b2b.execute` as its sole baseline permission. V2 dispatch remains **B2B = VAN ONLY; B2C = DRIVER ONLY**. The legacy role cannot grant Retail Driver App execution, general Dashboard/Finance/Security/Inventory authority or Van access without an active effective Van assignment.

`backend/tests/Feature/V2ExportedAccountRow5ScopeTest.php` provides a synthetic two-part security test.

| Boundary | Required assertion |
| --- | --- |
| Historical role semantics | Active global `B2B_DRIVER`; only `deliveries.b2b.execute` inherited; no global/store role escalation or invented historical live grants |
| Dashboard and Sidebar | No Security role management, Retail inventory or Wholesale orders business leaves; direct authenticated Security Center, Sidebar-role editor and Wholesale order HTTP requests return 403 |
| Channel separation | No `deliveries.b2c.execute`, `van.login`, orders/inventory/role/delete permissions; no permission-derived Retail/Wholesale inventory/order tenant scope |
| Retail Driver login and runtime | Even with a real *synthetic B2B-type driver profile*, Driver login and wallet with `app:driver` token return 403 |
| Van runtime and token | A `B2B_DRIVER` role without an effective Van assignment receives 403 for Van login and protected Van visits, including forged app token |
| Legacy driver-to-Van compatibility | Matching **active** driver profile is a necessary legacy bridge condition, not sufficient HTTP admission; foreign user's driver ID, null driver ID, inactive profile and no effective Van assignment fail closed |
| AR/EN | Both locale settings exercised against actual protected Dashboard/Driver/Van HTTP endpoints and Sidebar permission-scoped projection; no new view or translated string introduced |
| Persistence | Existing synthetic identity, historical role relation and B2B driver profile are preserved; no production user, ledger or uploaded files touched |

### Source-level limitations

The previous historical export does not establish the live account's role/override changes, activity, B2B Driver profile, Van assignment, tenant/store, effective permission/Sidebar projection, or audit trail. A reviewer with approved least-privilege **read-only** access must privately correlate the exact row-5 identity to its current user, global/store roles, overrides/DENY, activity, delivery and Van contexts and any anomalous cross-channel/SoD authority. Record only non-identifying approval evidence. No role deletion or migration should be performed based solely on the historical single-grant snapshot.

The test deliberately does not assert that every B2B Driver is forbidden from the Van app: `VanRuntimeContextResolver` supports a narrowly bound legacy active Driver-to-Van assignment compatibility path. A test passing without an assignment is not proof that the real account is assigned.

## Customer, Driver and Van applicability

- **Customer:** no Customer authentication, B2B pricing/credit, order flow, API response or Flutter code change. No new customer-side issue from this isolated test-only patch.
- **Driver:** existing Retail B2C-only login, authorization and wallet protected flow covered negatively; no Driver API/Flutter or dispatch code change. Separate `#2735` owns B2C Driver workflow.
- **Van:** existing authorized assignment/driver identity scope checked through the canonical resolver and HTTP middleware; no Van app/API/schema change. Existing `#2738` and `#2739` own actual Van operational workflow.

No public API, Dashboard Blade, permission config, migrations or production runtime changes; new test/doc files only. B2B Van / B2C Driver invariant is unchanged.

## Release and acceptance control

Use **one** issue-scoped branch `test/2910-v2-exported-account-authorization-boundary`, **one PR** with base `Foodex_V2`. Before merging the bounded regression, confirm latest-head Repository Policy, FOODEX V2 Isolation, full FOODEX Required CI Gate (Laravel tests, PHP/Pint/PHPStan, security/OpenAPI, AR/EN and applicable MySQL/Redis install/upgrade/recovery) all SUCCESS; no outdated SHA results or CI bypass. Manual device/browser UAT is `DEFERRED_TO_V2_FINAL` under #2503, but source-account reconciliation is **not** satisfied by a synthetic test. Keep issue #2910 OPEN pending authorized actual-account evidence and any needed independently approved remediation.

Absolutely no merge into `main`, tags, release artifacts, production account edits or deployment.
