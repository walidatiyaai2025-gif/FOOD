# V2 #2769 — T019R03 customer-service role-overlap audit

Source: FOODEX Unified RBAC/ERP/Sidebar discussion, 2026-11-09, table row T019R03.
Target: Foodex_V2 only.
Status: automated safety regression added; current-account classification and approved remediation remain UNVERIFIED.

## Scope and evidence boundary

The source describes a historical exported account with overlapping customer-service responsibilities. Historical export rows are NOT evidence of present production role assignments, tenant scope, user identity, or approval to mutate access.

The existing canonical Security Center is /admin/security?section=users; the guarded mutation is admin.security.users.roles. Role membership and effective permissions are derived from roles, role_user, user_store_roles, and user_permission_overrides, not a manually maintained Sidebar list. The audited RbacManager::replaceUserRoles() performs the existing validation and records user.roles_changed. We do not add a second authorization policy or infer that the name seen in a historic export uniquely identifies a live user.

## Regression evidence

The expanded test backend/tests/Feature/V2CustomerServiceRoleOverlapAuditTest.php uses synthetic users, seeded built-in roles, and the real web route:

1. An authorized Super Admin consolidates overlapping CUSTOMER_SERVICE_AGENT and CUSTOMER_SUPPORT assignments down to the reviewed agent role without modifying other accounts or built-in role grants. Excess support.manage permission disappears; agent permissions remain, and a persisted audit event identifies the operator and target.
2. A customer-service agent cannot modify another user's role assignments in Arabic or English. Denied requests preserve the target's role links and do not emit a successful role-change audit.
3. Security Center users view remains accessible to authorized Super Admin with Arabic/RTL and English/LTR direction; an agent receives HTTP 403.

No production identity, credential, exported personal record, or historical email is embedded in the test or release artifacts.

## Navigation and mobile impact

- Backend: Existing authoritative role-management route, RbacManager, audit log and permission model are exercised only. No schema, API, permission catalog, module, or entitlement contract changed.
- Dashboard: Reuses existing Security Center > Users role-assignment action; no new Sidebar entry, page, control or frontend asset. Server 403 stays authoritative.
- Customer: Not affected — no public API consumer route, payload or authentication flow changed.
- Driver: Not affected — no dispatch, wallet, role API or driver code changed; B2C remains Driver only.
- Van: Not affected — no route, assignment, territory or van contract changed; B2B remains Van only.
- Tenant/store: Consolidation is on the exact selected user. This test is not authorization to merge Retail and Wholesale roles. Existing retail/store and module/SoD acceptance suites remain required.

## Current-account completion gate (not yet evidenced)

Before declaring T019R03 complete, an authorized Super Admin must verify current assignments of the correct account in the real target environment; classify its business function and attached stores/channels; compare approved least-privilege roles and temporary/delegated access; document the approval/change ticket; and apply any needed adjustment through the existing audited Security Center. Verify that no integrations depend on removed permissions and perform an after-change access check. Keep account identifiers and approval reference in a protected internal audit record, not this repository.

No live account has been accessed or modified by this PR. If approved current-account evidence is unavailable, preserve the Issue open instead of treating historical export as remediation.

## Verification

- Focused Laravel: cd backend && php artisan test --filter=V2CustomerServiceRoleOverlapAuditTest
- Formatting: cd backend && vendor/bin/pint --test tests/Feature/V2CustomerServiceRoleOverlapAuditTest.php
- Full applicable Laravel/security, AR/EN, Repository Policy, FOODEX V2 Isolation and exact-head Required CI Gate from the same branch head.
- Manual final staging/browser/mobile acceptance: DEFERRED_TO_V2_FINAL per #2503, not a waiver of missing source/live-account evidence.

## Coding-only closure versus source-account acceptance (owner #2503)

The canonical Security Center role replacement, AR/EN guard, audit and current permission model already exist. This issue-owned test demonstrates them plus explicit store-role preservation and override reset. It adds no production role mutation, API, route, schema or mobile contract. Exact-HEAD required GitHub CI must succeed after final branch reconciliation before the coding-only implementation may merge into Foodex_V2.

The historical account's current identity/roles/overrides, organizational scope, an approved least-privilege target role set and a signed remediation/change ticket are **not** known from repository data. These remain mandatory confidential read-only/operator-reviewed final acceptance under #2503; no test fixture proves the live exported account has changed. If the private review reveals an unmet source implementation or unauthorized permissions, this issue must not be represented as final remediation; document and fix the discovered code gap under V2 governance. Do not disclose private account identifiers in GitHub.
