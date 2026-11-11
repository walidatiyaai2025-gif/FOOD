# V2 #2759 — T015R01 active user and 20-account inventory audit

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), row T015R01. **Parent:** #2503. **Merge target:** `Foodex_V2` only. Never `main`, releases, tags or production.

## What is implemented and what is not claimed

The canonical Administration → Security Center → Users panel already exposes authenticated, server-authorized current user records; an AR/EN active/inactive status filter; search; 15-row pagination; and role associations. Neighboring #2763 installed the read-only `assignment=roleless` filter, and #2760 separately owns the **role-registry usage** audit. This issue does **not** fork any of those implementations or change the shared view, controller, role-grant writer or business policy.

T015R01's historical **20 accounts** are an archival source observation, **not an asserted count of current users**. A synthetic 20-account Laravel/DB fixture exercises the existing production code path without touching any real account. Account identity and actual status/assignment must be reconciled with an authorized original export/current database by an appropriate operator before historical per-row acceptance; the public GitHub issue cannot carry real user identifiers.

## Authorization and accounting model

- **Domain/entity:** Administration / Users. **Navigation type:** existing Administration Hub → Security Center Users tab. **Placement:** REUSE; no new route or sidebar item.
- **Actor:** global Super Admin allowed on real `GET /admin/security` guarded by `security.view`. Store admin without global authorization receives 403 even when querying a known identity name. No provider-supplied account IDs are accepted and no cross-store disclosure occurs.
- **Classification:** active/inactive is persisted `users.is_active`, independent of role presence. Global assignments are in `role_user`; Retail store assignments are in `user_store_roles` and must not turn into global roles. Platform customer identities may be legitimately roleless; unassigned noncustomers are **review-required**, not automatically defective. The filter combines with search, status, and pagination. No temporary grants or automatic cleanup.
- **Allow/deny/readiness/SoD:** existing route authorization is authoritative; label and row visibility are not new security grants. Read-only GET cannot approve, post, delete, change finances, change entitlements, or override the maker/checker boundary. No module is enabled by these tests.
- **UI:** existing AR/EN, RTL/LTR, mobile-responsive table, labels, keyboard controls and pagination are reused unchanged. No new dashboard view or CSS/JS primitive.

## Synthetic regression matrix

`backend/tests/Feature/V2ActiveUserAccountClassificationAuditTest.php` checks:

| Cohort | Accounts | Active | Inactive | Source of role/classification |
| --- | ---: | ---: | ---: | --- |
| Global B2B operator | 6 | 4 | 2 | `role_user` |
| Scoped Retail store operator | 4 | 3 | 1 | `user_store_roles` |
| Platform customer | 5 | 4 | 1 | `is_platform_customer=true`, no admin role required |
| Unassigned / requires review | 5 | 3 | 2 | neither global nor store role |
| **Synthetic total** | **20** | **14** | **6** | Not historical production evidence |

Tests assert authenticated real route, 20-item search count, page 1=15/page 2=5, active/inactive status results, ten roleless results and three inactive-roleless results, distinct customer/review classification, Arabic RTL status filtering, store-actor HTTP 403, unchanged user status, no implicit role grants or removals, and no `user.status_changed` audit writes.

## Customer / Driver / Van impact review

- **Customer:** customer accounts may have no management role, but this change only tests read-only Security Center logic. No Customer login, credentials, session state, permissions, orders, prices, API or navigation were changed.
- **Driver:** no Driver account, role, delivery, proof, notification, token or B2C-only dispatch contract changed.
- **Van:** no Van account, route, visits, inventory, token, offline or B2B-only fulfillment contract changed; no Driver fallback introduced.

All three Flutter source trees, OpenAPI, routes, backend runtime logic and database migrations are unchanged. No app-specific mobile Issue is necessary for a test-and-documentation-only change.

## Acceptance and evidence boundaries

Run `cd backend && php artisan test --filter=V2ActiveUserAccountClassificationAuditTest` and the repository's full exact-head Required CI gate (Pint, PHPStan, Laravel full suite, AR/EN, repository/V2 isolation policy and MySQL/Redis fresh install/upgrade/recovery). Report only actual GitHub Actions results on the PR head.

**Outstanding:** reconcile the 20 real source users against an independently authorized, time-stamped current account export, including identity type, status, effective global/store scope and exceptions. Document aggregate, non-PII evidence and permission-filtered reconciliation; do **not** infer historic names or live account status from this synthetic fixture. If the source row requires that verification, leave #2759 open after code merge rather than falsely closing it. Manual browser/device/UAT acceptance is deferred to final V2 gate #2750 under latest #2503 owner instruction.
