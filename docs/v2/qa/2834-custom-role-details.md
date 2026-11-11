# V2 P098 — Custom role configuration details

Issue: #2834; parent #2503. Integration target: `Foodex_V2` only.

## Placement and navigation

- Domain/entity: Administration → Security & Users → custom RBAC Role.
- Actor: an authenticated Dashboard operator with canonical `security.view`. Mutations remain under `roles.manage`.
- User goal: inspect saved role identity, description, assignment scope, associated permission modules, saved assignments and configured approval ceilings before editing role access.
- Journey/entry: existing Administration Hub → Security & Users → Roles tab → expand the exact role card. No new route, Sidebar node, tab or parallel authorization system.
- Decision: EXTEND the existing Role 360 card with a read-only nested evidence panel; reuse the current page chrome, role search and role edit flow. Primary pattern: Record 360 / Details.
- Desktop/tablet/mobile: existing responsive `role-grid`, with one role per column at narrow widths; native `<details>` is keyboard operated; system label copy is keyed in Arabic and English, with inherited RTL/LTR page direction.
- Record authority: `backend/app/Http/Controllers/Admin/SecurityController::index` (HTTP GET `/admin/security?section=roles`, Gate `security.view`); existing `App\Models\Role`, `Role::permissions`, `EnterpriseApprovalLimit`. API `GET /api/v1/admin/security/roles` already supplies code, description, scope, archived/active state, assignments and permissions under the same gate.
- Classification: description = legitimate free input on the existing edit form; scope = canonical enum; permission codes = advanced technical read-only identifiers with localized group labels; stored approval amounts = read-only policy values, not new input.

## Display semantics and authoritative boundaries

1. A custom role's `code` is immutable and remains reserved after archive; the saved description is shown without substituting a built-in role's narrative.
2. `scope=store` describes the **assignment type**, not a hard-coded store. Stored global/store assignment counts are displayed separately. Exact store names and per-store saved assignment counts come from grouped authoritative `user_store_roles` joined with `stores` (not user identities or inferred access). Exact eligible stores are determined by each user's role assignment and server-authoritative context, not inferred from the role.
3. Permission-module groups derive from stored `permission_role` links. This is not a second entitlement system. The actual user may be inactive/denied, the module disabled/unentitled, or a tenant/channel/store boundary may block a request. Do not display these links as an effective-access ALLOW.
4. Active `enterprise_approval_limits` are read by role ID on the authorized page, with precise decimal strings, currency, permission code and scope classification. Inactive limits are not advertised as enforceable. Amounts are configuration evidence **only**; the real action enforces current actor/role/entitlement, maker/checker and approval policy separately. No financial, credential, user, store or operational data is modified.
5. Role/archive and active state remain in the existing outer Role card. The custom details panel is visible to `security.view` readers even when they cannot manage roles; no new grants, endpoints, migrations, sidebar policy or audit-writing flows were introduced.

## Scoped regression matrix

- AR RTL + EN LTR HTTP read of exact custom role, immutable identifier, actual description, permission-module group and active financial ceiling.
- Inactive ceiling excluded; saved assignments and role/limits not changed by a read.
- Unauthorized, store-only without global permission, inactive role denied at HTTP route; API role list remains role-gated, and write attempts remain 403 for `security.view`-only actor.
- Existing security role/card, role editing, module readiness, tenant isolation and SoD server policies remain authoritative.
- Dedicated test: `backend/tests/Feature/V2CustomRoleDetailsContractTest.php`.

## Customer / Driver / Van

- **Customer:** no Flutter, login, token, commerce/API or credit contract changes. The change is an authenticated Dashboard-only read surface; no Customer app payload changes.
- **Driver:** no dispatch/wallet/collection/API, assignment or Flutter changes; B2C DRIVER ONLY boundary unaffected.
- **Van:** no routes/visits/territory API, offline queue or Flutter changes; B2B VAN ONLY boundary unaffected.

## Acceptance scope

This is not a browser/device certification, a claim that every entitlement is enabled, or authority for production data. Complete exact-HEAD Repository Policy, V2 Isolation, AR/EN, Laravel/Pint/PHPStan/security, MySQL/Redis install/upgrade/recovery and terminal Required CI before merging. Per owner #2503, manual browser/UAT/device acceptance is **DEFERRED_TO_V2_FINAL**, not recorded as executed. Keep the issue OPEN if any source-level P098 requirement or security acceptance remains unimplemented, regardless of a green partial PR.

No `main`, tag, release, production deployment or confidential account lookup.
