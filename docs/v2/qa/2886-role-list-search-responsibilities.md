# V2 #2886 / P363 — searchable role catalogue with descriptions and responsibilities

**Target:** `Foodex_V2` only. Parent issue #2503. Work proceeds on the existing `admin.security.index?section=roles` page and its established `SecurityController::index` server authorization, `BuiltInJobRoleCatalog` names/responsibilities and `Role` records. No new permissions, REST endpoints or independent Sidebar authorization.

## Implemented UI contract

- The existing roles panel shows the canonical role display name, persisted code, translated description, built-in job responsibilities, access boundaries, scope, availability and status. It retains the current detail/modal editing controls and server-calculated grantability. No new privileged control is introduced.
- A dedicated bilingual search control filters **only the already-rendered role cards**. It searches existing role code, primary name, translated business name, description, responsibilities and access boundaries using case-insensitive Unicode normalization, then updates an accessible live result count. The empty message appears when no cards match. Results are never an authorization decision.
- Search is client-side progressive enhancement: with JavaScript disabled, the complete role catalogue remains visible; the server controls which page and actions the actor can access. The Users tab retains its own existing server-side filter, and permission picker search remains independent.
- The Roles panel is available only after the pre-existing server `security.view` Gate check; create/update/clone/archive continue requiring the pre-existing `roles.manage` and role-grantability guards. Search does not mutate role, permission, audit, entitlement or assignment rows. Inactive/archived roles retain their original read-only restrictions.

## Backend and AR/EN acceptance

`backend/tests/Feature/V2RoleListSearchResponsibilitiesTest.php` checks authorized role catalogue GET in Arabic RTL and English LTR, localized input and no-match messaging, correct role card search hooks, system role and existing responsibility presentation, absence of database changes, independence of the Users tab server filter and roleless HTTP 403 on an attempted forged roles query. The full existing Laravel security/RBAC suite remains authoritative for grant-scope, store/channel/tenant, built-in-role protection, auditing and denied mutations.

## Mobile impact — Customer / Driver / Van

**Customer:** no Flutter, customer login/API, role, account, order or permission contract changes.
**Driver:** no Flutter, GPS, dispatch, delivery, permission or HTTP contract changes. B2C remains DRIVER ONLY.
**Van:** no Flutter, visit, routing, wholesale collections or permission changes. B2B remains VAN ONLY.

Only Dashboard Blade, localized text, the existing interaction runtime and a new read-only Feature test are modified.

## Approval gate and remaining evidence

Before merge, Repository Policy, V2 Isolation, localization AR/EN, PHP lint/Pint/PHPStan/security/OpenAPI, full Laravel tests and MySQL/Redis fresh-install/upgrade/recovery must all succeed on the **exact latest head SHA**. Reconcile any other integrated worker commits without force-pushing; preserve worker-owned files. Independently test isolated staging browser keyboard focus and screen-reader result announcement, mobile 320/375px and desktop widths, mixed Arabic/English names, no-JS fallback, empty result, role description/responsibility matching and multi-role/tenant negative requests. Do not close Issue #2886 until this external accessibility and business acceptance is evidenced.

No modification to `main`, production, releases/tags, destructive user or financial data, or active workers' branches.
