# V2 #2912 — historical account role audit, Table 5 row 7

## Provenance / confidentiality

- Parent #2503; source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, native Table 5, data row 7.
- The historical export lists role **B2B_DRIVER** and **one distinct inherited permission**. The canonical current built-in role declares only **deliveries.b2b.execute**. Neither proves this account's present authorization.
- The account's identifier, name, handle, email, telephone, passwords and any actual assignments are omitted from public issues, tests and commits. All tests use a synthetic user and profile.

## Implementation / authority

Canonical implementation is reused: backend/config/job_roles.php, User effective permission logic, OperationalTenantScope, AdminNavigation, EnsureDriverRuntimeAccess, EnsureVanRuntimeAccess and VanRuntimeContextResolver. This is an audit/regression contribution, not an added permission or a new identity model.

The dedicated backend/tests/Feature/V2ExportedAccountRow7AuthorizationTest.php proves:

1. Only the legacy Wholesale execution role and single declared capability are inherited; no Retail Driver, VAN_OPERATOR, role management or privileged Dashboard navigation is inferred. Existing protected Security Center and Sidebar HTTP paths deny this actor.
2. An active historical B2B Driver profile alone cannot use current Retail-only Driver runtime, nor Van runtime without its own effective Van assignment. Tests exercise both Arabic and English locales.
3. Forging an explicit global Retail deliveries.b2c.execute ALLOW does not cross the B2B/B2C domain boundary. Direct Driver/Van HTTP still denies and the original role/identity is retained.

The current Van compatibility path permits an eligible legacy driver only when an *effective assigned Van context* satisfies server-side authority; this test does not weaken or claim to disable that path. B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged.

## Impact matrix

| Surface | Change / evidence |
| --- | --- |
| Backend | Only disposable Laravel security regression fixtures and direct HTTP checks |
| Dashboard | Existing Security Center / Sidebar authority checked; no new UI, route or permission |
| Customer | Not affected; no auth, customer, store, pricing, Flutter or public API contract changes |
| Driver | No app or API contract change; existing B2C-only middleware denial verified |
| Van | No app or API contract change; existing assignment-required middleware denial verified |

No customer/driver/van implementation issues are required for this test-only contribution. Should actual reconciliation establish a mobile defect, create the separate affected-app V2 issue and link it before any app change. Sibling rows #2910, #2911 and #2913 are not modified or claimed.

## Unverified source-to-live acceptance

A separately authorized read-only inspection must reconcile the *specific confidential historical account* with its current type (Dashboard, Customer, Driver or Van), active flag, global/store roles, role overrides, active driver/van assignments, store/channel/tenant/module restrictions, effective permissions and actual Sidebar. Document that evidence only in an access-controlled location; historical exports alone do not justify mutation/deletion or new privileges.

No live identity was queried or changed, no runtime acceptance is claimed, and **Issue #2912 must remain OPEN** until its source-specific current-account reconciliation is complete. Manual staging/browser testing is deferred under V2 master #2503, not automated checks.

Delivery rules: one branch, one PR base **Foodex_V2 only**, required Repository Policy + V2 Isolation + full Required CI on exact final head GREEN before integration. No main, release, tag or production deployment.
