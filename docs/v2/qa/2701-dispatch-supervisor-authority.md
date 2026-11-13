# FOODEX V2 #2701 — Dispatch Supervisor (T122R21)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / `T122R21`; master #2503. **Integration:** `Foodex_V2` only. No `main`, tags, release, staging/production deployment.

## Canonical role, actions and placement

- Persona: `DISPATCH_SUPERVISOR`, seeded global **tenant-scoped** Wholesale dispatcher in the existing Field Operations domain. Existing built-in permissions: `orders.view`, `orders.dispatch`, `drivers.tracking.view`, `drivers.b2b.view`, `drivers.b2b.manage`, `reports.view`. No `orders.approve`, Retail Driver, Finance, security, territory-management or delivery-execution privilege is added.
- Existing authoritative entry points, not new screens: **Orders & Operations** → `order_operations` → `admin.operations.orders.index`; **Fleet & Field Operations** → `field_ops_overview`, `field_ops_vans`. Both pages perform independent effective permission and operational channel/store scope checks server-side.
- Canonical B2B channel eligibility is `config('admin.channels.b2b.global_roles')`. This role was missing there despite a pre-existing job-role permission bundle. Added only `DISPATCH_SUPERVISOR` to B2B `global_roles`; did not grant B2C or alter any permission, role, user assignment or existing Finance/Treasury duties.
- `FieldOperationsController::b2bStoreIds` already used the canonical `OperationalTenantScope::allowedStoreIds('drivers.b2b.view','B2B')` for `FLEET_MANAGER` (merged sibling #2700). The same single scoped decision now also covers **only** `DISPATCH_SUPERVISOR`. Global/store `DENY` overrides are applied, inactive/foreign/Retail stores excluded. The other pre-existing fallback roles are unchanged. This preserves the owner-only store scope and avoids granting a new generic privileged role.
- Business action boundary: Dispatch Supervisor oversees orders/assignments in **B2B Van** fulfillment. It is **not** a Van login principal, does **not** execute a delivery using Driver or Van credentials, does not accept Retail orders, and cannot approve accounts, mutate Finance, change roles, assign territory policy or override maker/checker. The existing Orders service/controller retains status-transition, authorization, idempotency and audit logic; this change does not bypass it.

## Automated acceptance

`backend/tests/Feature/V2DispatchSupervisorAuthorityTest.php` runs against seeded built-in roles and actual Laravel named HTTP endpoints:

1. Exact seeded least-privilege permission catalog; B2B global-only admission, no B2C, Finance, Security, direct delivery-execution or territory-management grant; nonempty AR/EN persona labels.
2. Authorized B2B operational stores exposed by canonical permission/channel scope; AR/RTL and EN/LTR Sidebar projection and genuine Orders and Field Operations HTTP 200, preserving same domain placement.
3. Direct Retail B2C dashboard/orders and foreign Retail field-operations requests denied, and Finance/Security direct requests remain 403; `orders.dispatch` does not create `deliveries.b2c.execute` or `deliveries.b2b.execute`.
4. Two Wholesale stores: store-scoped `drivers.b2b.view` DENY hides foreign B2B store from real Field Operations HTTP and retains valid own-store access.
5. Global `orders.view` DENY removes authorized Orders scope and Sidebar navigation and makes direct Orders GET return HTTP 403.
6. Global `drivers.b2b.view` DENY blocks Van list and effective store scope; inactive/roleless principals receive 403 without data or role mutations.

No new dashboard fields, migrations, public API payloads, user permission grants or Android/Flutter source files are introduced. Existing Dashboard responsive AR/EN components are reused; final live/browser/a11y UAT is **DEFERRED_TO_V2_FINAL** to master #2503 and #2750. Automated security defects and required CI are not deferred.

## Customer / Driver / Van impact

| Surface | Assessment / proof |
| --- | --- |
| Backend and Dashboard | **Affected:** global B2B administrative channel admission and server B2B store filtering for the existing Dispatch Supervisor role only; authenticated HTTP tests required and provided |
| Customer | **Not affected:** no Customer token, checkout, order/price/notification endpoint, navigation or Flutter contract changes |
| Driver | **Not affected:** no B2C driver token, assignment, status, delivery, wallet or Flutter changes; B2C remains **DRIVER ONLY** |
| Van | **Indirect administrative effect only:** supervisor can see permitted B2B operational Dashboard context; no Van app/API/assignment payload/lifecycle edit. B2B remains **VAN ONLY** |

A newly observed mobile API contract change would require a separate V2 mobile issue; none is made here.

## Integration and CI contract

The one issue branch is `test/2701-v2-dispatch-supervisor-authority`; create **one PR only** targeting `Foodex_V2`. Exact-head Repository Policy, FOODEX V2 Isolation Gate, PHP lint/Pint/PHPStan/Composer security/OpenAPI, AR/EN localization, full Laravel backend suite, MySQL/Redis clean installation/upgrade/recovery and terminal `required-ci-gate` must all succeed before merge. Failed checks must be repaired on the **same branch and same PR**, never bypassed. After merge, verify PR state, ancestor commit, issue acceptance, release worker lease and close only the completed atomic leaf. Parent #2503 remains open.

No `main`, cPanel production, release artifact publishing or unattended deployment is authorized.
