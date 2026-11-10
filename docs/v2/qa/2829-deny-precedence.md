# V2 P092 — Explicit DENY precedence over role inheritance and scoped ALLOW

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph **P092**
- Issue: [#2829](https://github.com/walidatiyaai2025-gif/FOOD/issues/2829), parent #2503
- Delivery: issue-scoped `feat/2829-v2-deny-precedence` → **`Foodex_V2` only**
- No main promotion, release, production changes, migrations or user-data rewriting.

## Existing authoritative implementation

`App\Models\User::hasPermission()` is the server authorization decision invoked by Laravel `Gate::define()`; its effective-permission projection is `User::effectivePermissionCodes()`, used by the Security administration UI and grant ceilings. The canonical `user_permission_overrides` table stores per-user `global` and `store:<id>` effects; role permissions remain independent. Neither the Sidebar nor local UI preference is a permission authority.

Previously, a store-level `allow` returned early before a global `deny` was inspected. Separately, the effective-permission collector processed a global `deny` then overwrote it with store `allow`. This could disagree with the intended deny-first model even when role templates and tenant identity were otherwise valid.

The V2 correction applies this priority for an ordinary user:

1. Any applicable **global or exact-store `deny`** blocks the permission, even if another role or scope grants `allow`.
2. In the absence of deny, an exact-store `allow` still requires valid active store/channel role context.
3. Global `allow` remains limited by active global role domain and channel context.
4. Otherwise normal inherited active role permissions apply. The existing protected `SUPER_ADMIN` policy and its prohibition on individual overrides are not changed.

A denied permission is also removed from `effectivePermissionCodes()` so security editors and navigation projection cannot falsely advertise the authority. No independent Sidebar visibility rules are added.

## Verified-by-tests contract

Focused `backend/tests/Feature/V2DenyPrecedenceAuthorizationTest.php` covers:

- Custom globally-scoped reviewer role initially authorizes `GET /api/v1/admin/security/permissions`; a global `security.view` deny removes inherited effective access and yields **403** from the real API, while leaving the shared role template intact.
- Two conflicting records, global `inventory.view=deny` plus `store:<id>=allow`, yield **deny**, no effective privilege and **403** on the real Retail inventory workspace URL.
- An exact-store `inventory.view=deny` cannot be inherited back in that store but does not remove the permission from another independently authorized Retail store. Authorized second-store direct URL remains available.

The existing `EnterpriseRbacTest` and `V2CustomRoleOverrideIsolationTest` are regression dependencies for grants, audit logs, role mutation protections and tenant/store separation. The required CI must run at the PR's exact final head. Do not mark this document as proof of tests until CI records success.

## Surface and mobile-impact classification

| Surface | Scope |
| --- | --- |
| Backend / API | **Affected**: existing `User::hasPermission()`, Gate, global and store effective permission projections. No new endpoint, schema or token contract. |
| Dashboard | **Affected authorization**, not UI layout: existing AR/EN permission labels and Retail inventory route use the same server authority. No new translation key or visual control; AR/EN and RTL/LTR remain unchanged. |
| Customer App | **No Flutter/app contract changes**. Current customer browsing, login, order and pricing APIs are unchanged. Cross-app authentication and permissions require separate validation when an app-specific role receives a user override. |
| Driver App | **No Flutter/app contract changes**. B2C-only Driver routing/auth semantics unchanged; no dispatch, token, state or app UI edits. |
| Van App | **No Flutter/app contract changes**. B2B-only Van routing/auth semantics unchanged; no dispatch, token, state or app UI edits. |

The change is a deliberate fail-closed RBAC enforcement across existing consumers; there is no new permission grant or extra mobile endpoint. If CI or review shows a real app-specific impact, link a dedicated V2 MOBILE issue before acceptance.

## Acceptance boundary

P092 is not accepted on a source diff alone. Required checks at the exact implementation head must pass: repository policy, V2 isolation, backend tests and static analysis, security and applicable Laravel/database gates. Integration and all evidence must be attached to #2829 and its one PR. Keep #2829 open if integrated runtime or other required acceptance is not independently verified.
