# #2805 / P054 — Sensitive-function individual permission exception boundaries

## Authority and behavior

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P054; parent #2503. Integration is **Foodex_V2 ONLY**. No main push, release, deployment, live staging UAT or production changes.

This is a **fail-closed individual-exception policy** within the existing `RbacManager` and `User::hasPermission` engine, not a new role, module entitlement, or alternative Gate/SoD system. Privileged user `allow` overrides **cannot create** sensitive permissions; instead these require an assigned, active role plus the existing action-specific authorization (company/tenant/store/channel, module readiness, workflow limits and maker/checker where applicable). A privileged role grant alone also does not waive action-level approval. Existing inherited permissions continue to work, and explicit individual `deny` remains effective.

The scoped `SensitivePermissionOverridePolicy::ROLE_ONLY_PERMISSIONS` list covers platform/RBAC/security/user lifecycle, system updates, finance posting/approval, WhatsApp activation/emergency, chatbot runtime, demo-data and push/SMS settings. This list is explicit; routine `orders.manage` and `catalog.edit` remain eligible for separately authorized per-user overrides. The V2 policy does not add a new emergency bypass or unrestricted temporary delegation; such workflows are separate governed capabilities tracked under V2 security/SoD issues.

### Server boundary

- `PUT /admin/security/users/{user}/permissions` keeps its existing Gate `users.roles.manage`, actor grant ceiling, role/channel/store/tenant validation, role reconciliation and audit.
- The existing `RbacManager::replaceUserPermissions` now rejects a selected sensitive permission that is absent from the active role baseline, with bilingual validation feedback, **before writing any user permission overrides**.
- An old database `allow` for a sensitive code is not deleted silently; effective authorization now ignores the escalating part while honoring role-based inheritance and any explicit `deny`. This applies to `User::hasPermission` and `effectivePermissionCodes`, so Web Gates and API authorization cannot rely on a stale direct grant.
- Role assignments, user/store permission rows, unrelated permissions, finance ledgers and audit histories are not migrated or deleted. The existing canonical Security Center is reused; no new page or Sidebar route. An independent SoD approval workflow is **not** claimed to be built here.

### Acceptance matrix

| Scenario | Expected result | Regression |
|---|---|---|
| Super Admin attempts individual `finance.post` allow for a user without inherited role permission | HTTP validation rejection; no override or mutation audit | `test_sensitive_user_allow_is_rejected_at_actual_admin_http_boundary_without_mutating_access` |
| Legacy direct `security.view`, `security.approve`, `finance.post` allow rows | No effective permission; protected Dashboard Security HTTP route denied | `test_legacy_sensitive_allow_rows_cannot_grant_route_or_effective_permission` |
| Existing role actually grants `finance.post` | Baseline persists, normal finance controls still apply | `test_sensitive_role_inheritance_remains_valid_and_user_deny_still_wins` |
| Explicit `deny` of inherited finance permission | Denied in `hasPermission` and effective set | Same |
| Ordinary `orders.manage` individual allow | Continues to function; no blanket breakage | Legacy-row scenario |
| Retail store / foreign store / wrong channel | Existing role scope, StoreContext, tenant and per-route checks remain in place; policy never bypasses them | Existing Enterprise RBAC cross-store tests + mandatory Required CI |

### Customer / Driver / Van impact classification

- **Customer:** no change to storefront, pricing, checkout, credit, APIs, customer identity or Flutter source. Only server-side operator security/finance control-plane permission codes are restricted from direct per-user `allow`; customer-facing permission contracts unchanged.
- **Driver:** no changes to B2C assignment, wallet, proof, notification, login or mobile source; existing `deliveries.b2c.execute` and `drivers.b2c.*` are not in the restrictive set. The B2C-only Driver invariant stays unchanged.
- **Van:** no changes to B2B visits, territory/dispatch, consignment, login or mobile source; `van.login`, `deliveries.b2b.execute`, `drivers.b2b.*` are not in the restrictive set. The B2B-only Van invariant stays unchanged.
- Shared backend `User` authorization is impacted for **security/control-plane codes** only. Where any API uses one of the specified sensitive codes, it now fails closed for a stale user `allow`; no app-specific route/API shape is changed.

## Verification and limits

Focused tests: `php artisan test --filter=V2SensitiveFunctionExceptionPolicyTest` from `backend/`. Verify PHP Pint, PHPStan, backend full suite, localization AR/EN, Repository Policy, FOODEX V2 Isolation Gate, exact-head Required CI including MySQL/Redis fresh install/upgrade/recovery. Record exact CI URLs, latest SHA and PR merge evidence in Issue; no success claim before verification.

P054 is a fail-closed restriction, not a claim that all SoD, maker/checker, temporary delegation or independent exception approval workflow functionality exists. Other granular policies and product-specific approval journeys remain their own V2 Issues.
