# V2 #2767 — T019R01 overlapping account role authority

## Scope and evidence boundary

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, AUDIT `T019R01`; parent #2503.
- Isolated target: `Foodex_V2`. The historical account mentioned by the document is **not** a test identity, a reliable snapshot of current privileges, or authority to change live account assignments.
- This increment only creates synthetic role/store/override regression tests. It does not query, display, rename, delete, or modify any actual user's account, historical exported data, or production role pivots. No custom independent authorization policy is introduced.

## Existing server-side authority

The implementation uses the existing `User::hasPermission`, `User::effectivePermissionCodes`, `OperationalTenantScope::allowedStoreIds`, `AdminNavigation::groupsFor`, `CoreReferenceSeeder` and the canonical `user_permission_overrides` records. Two distinct operational roles are intentionally represented by one synthetic user to verify that role union never bypasses the store/channel boundary, explicit DENY, user deactivation, or the Security Center's direct HTTP authorization.

| Actor / condition | Expected decision | Regression |
| --- | --- | --- |
| Active Wholesale global Customer Service Supervisor + one assigned Retail Customer Support store role | B2B granted from authorized global role; B2C access limited to exactly the assigned Retail store | `test_overlapping_global_and_store_roles_keep_wholesale_and_retail_authority_separate` |
| Same combined actor asks for unassigned Retail store data or Security Center | No cross-store authority; role combination is not Super Admin | Same test, including AR/EN direct HTTP 403 |
| Both roles inherited `orders.view` but an explicit global DENY exists; conflicting Retail store ALLOW present | DENY wins on B2B and B2C, including effective-permission and tenant-scope projections | `test_global_deny_still_wins_over_both_inherited_roles_and_store_allow` |
| Forged Retail ALLOW claims a foreign store with no store-role assignment | No direct permission or projected Retail store authorization | `test_forged_foreign_store_allow_cannot_extend_a_mixed_role_account_and_deactivation_clears_access` |
| Actor is deactivated after role and override history exists | No effective permission, tenant scope or Dashboard navigation; historical assignments preserved for audit | Same test |

The synthetic fixture uses `CUSTOMER_SERVICE_SUPERVISOR` (global Wholesale) and `RETAIL_CUSTOMER_SUPPORT` (one Retail store), **not** a claim about the historical account's actual roles. No additional access entitlement, user status or role change is introduced.

## Surface impact and parity

- **Backend / Dashboard:** Feature regression only; both existing HTTP Security Center authorization and navigation scope are asserted. No new navigation item, component, controller, route or UI strings; current AR/EN, RTL/LTR and responsive behavior remain unchanged.
- **Customer app:** no public API, authentication, role payload, checkout, notification, Flutter, or runtime change.
- **Driver app:** no public API, assignment, status, finance, location, Flutter, or runtime change; B2C remains Driver-only.
- **Van app:** no public API, route, trip, assignment, Flutter, or runtime change; B2B remains Van-only.
- No app issue is triggered by this test-only change. If a later real remediation changes a mobile-consumed contract, open and link the affected app-specific V2 issue before merging that remediation.

## Acceptance still requiring authorized evidence

This synthetic code contract **does not close T019R01**. A privacy-preserving, authorized, read-only attestation must compare the historical source with the current account classification, active role pivots and user overrides, effective global/store/tenant/channel permissions, actual Sidebar and direct HTTP authorization, and any Finance SoD exposure. A discrepancy should be isolated in a separately reviewed implementation with its own regression and a supported account remediation plan. Do not put account identifiers, emails, secrets or live permission exports into GitHub.

Verify the issue-scoped test using `php artisan test --filter=V2OverlappingAccountRoleAuthorizationAuditTest` under `backend/`, and record exact-head Repository Policy, V2 Isolation, and Required CI results for the PR. Do not merge on a failed or pending required check. No `main`, release tag or deployment is authorized.

## Current account proof checklist (privacy-preserving, read-only)

An authorized reviewer must supply a dated, de-identified evidence record that maps the source row to the *current* account classification, account-active state, effective role grants, scoped store assignments, direct ALLOW/DENY precedence, active module and entitlement readiness, Finance maker/checker conflicts, and the real Dashboard-route/Sidebar authorization matrix. Distinguish historical source assertions from observed present state; record each mismatch and its proposed remediation owner. Never infer that an archived overlapping role is still assigned, or perform role/user writes from a historical export alone.

This proof is outside the synthetic fixture. The present PR may integrate its tests after green required gates, but it does not satisfy this live-source checklist and therefore cannot by itself close #2767.
