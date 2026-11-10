# V2 T087R06 — customer-service identity administration boundary

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, table row `T087R06`; leaf Issue #2826 / parent #2503.

**Merge target:** `Foodex_V2` **only**. This work does not authorize `main`, a production release, a deployment, or a release tag.

## Authoritative system behavior

The service-specific Dashboard profile already reuses `AdminNavigation::groupsFor` and existing permission-aware named routes; it must *not* invent a separate Sidebar allowlist or modify role meanings. Read-only and mutation requests must be denied by the server even when a staff member manually types the URL.

| Actor | Positive service access | Security/users/roles access | Scope |
| --- | --- | --- | --- |
| `CUSTOMER_SERVICE_MANAGER` | `orders.view`, service workspace | Deny `security.view`, `roles.manage`, `users.view`, `users.roles.manage`, `users.status.manage` | Tenant/Wholesale service |
| `CUSTOMER_SERVICE_SUPERVISOR` | Service/order work | Same deny | Tenant/Wholesale service |
| `CUSTOMER_SERVICE_AGENT` | Service/order work | Same deny | Tenant/Wholesale service |
| `CUSTOMER_SUPPORT` | `orders.view` | Same deny | Tenant/Wholesale support |
| `SUPER_ADMIN` | Protected control plane | Existing authorized management APIs remain available | Platform control plane |

The proof is in `backend/tests/Feature/V2CustomerServiceSecurityBoundaryTest.php`:
- Seeded role definitions and real permission pivots reject security-administration privileges while retaining authorized order view.
- Both AR/EN / RTL-LTR service sessions render service navigation without security-role/layout links; an existing orders page remains reachable.
- HTTP `403` on `admin.security.index`, `admin.sidebar-access.roles`, `admin.sidebar-layout.index` and direct write calls to user roles, user permissions, user activation status, and role visibility.
- Denied calls cannot deactivate the target, change the target role, persist permission overrides, or write Sidebar-role mutation audit entries.
- Built-in role reconciliation drops a stale accidental security grant but retains service `orders.view` permission. No built-in role or production account is changed by this test.

## Effects by delivery surface

| Surface | Impact | Contract |
| --- | --- | --- |
| Backend/RBAC | Focused **regression evidence** for existing authoritative routes and seeded built-in roles | No new endpoint, permission grant or migration |
| Dashboard | Focused role-specific AR/EN Sidebar and real service page evidence | No new visual control or direct UI edit |
| Customer Flutter | Not affected | No customer REST schema, session or application code change |
| Driver Flutter | Not affected | No driver REST schema, session or application code change |
| Van Flutter | Not affected | No van REST schema, session or application code change |

The permission model remains backend-authoritative: B2B remains VAN-only and B2C remains DRIVER-only; no fulfillment paths, store ownership, entitlements, or mobile contracts have changed. No new mobile implementation issue is needed for a tests-and-documentation-only change.

## Acceptance / verification

```bash
cd backend
php artisan test --filter=V2CustomerServiceSecurityBoundaryTest
vendor/bin/pint --test tests/Feature/V2CustomerServiceSecurityBoundaryTest.php
```

Then verify the exact PR head passes Repository Policy, V2 Isolation Gate, backend static analysis/tests, AR/EN localization, and all child jobs of Required CI. Green static and HTTP tests establish RBAC/route behavior, not a physical device viewport audit; no claim is made of fresh browser visual verification for unchanged layouts. No deployment, production data inspection, or account mutation is permitted by this work.
