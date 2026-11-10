# V2 #2925 — privacy-preserving historical RBAC account audit

## Source and acceptance boundary
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native Table 5, data row 20. Parent: #2503; target: **Foodex_V2 only**.
- This is a historical exported-account observation, **not** an authenticated snapshot of the current user's active status, live assignments, overrides, module entitlements, store access, or financial workflow permissions. Never copy the source user's name, email, row fingerprint, credentials or other identifying attributes into a public issue, commit, test or log.
- The synthetic test models a **representative subset** of legacy wholesale inventory/procurement/accounting/treasury job roles, rather than a re-creation of the specific exported account or its complete role combination. It cannot certify that user's current entitlements or effective permissions.
- No production account was queried or modified. No access was granted, no role reassigned, no production settings altered.

## Server-side audit matrix
| Boundary | Synthetic regression assertion | Remaining evidence |
| --- | --- | --- |
| Catalog role and active state | Representative roles resolve to active, globally scoped built-in identities in the seeded V2 database | Live user's authenticated account-type/status/role assignment and historical-to-current mapping |
| Inherited permissions | Server-computed unique inherited grants include permitted Wholesale inventory read but exclude privileged roles-management | Full, current role grants and scoped overrides for the actual account |
| Retail versus Wholesale | Wholesale synthetic actor cannot open Retail inventory via direct HTTP and cannot see its Retail-only Sidebar item | Authorized cross-company/store live runtime audit in isolated V2 staging |
| Security control plane | Synthetic actor cannot open the role-administration HTTP route; Sidebar visibility grants no authority | Actual user deny/allow inventory and configured module entitlement/readiness review |
| Explicit user DENY | Global `inventory.view` DENY defeats inherited role union for B2B and direct HTTP (AR and EN) | Current per-user global/store override records and approval/audit trail |
| Inactive identity | Disabled synthetic actor receives no tenant-store inventory scope or Dashboard Sidebar entries | Current identity lifecycle, session/token invalidation for actual user |
| Finance maker/checker | Existing authoritative `EnterpriseFinanceApprovalFoundationTest` and `V2FinanceCommercialAccessMatrixTest` cover independent maker/checker/poster and negative financial paths | Individual real actor's permitted Finance/Procurement/Treasury actions, SoD conflicts and delegated limits are **NOT** certified by this test |

The synthetic feature assertions use canonical `User::hasPermission`, `User::inheritedPermissionCodes`, `OperationalTenantScope`, `AdminNavigation` and actual Dashboard HTTP routes. They do **not** create a new Sidebar visibility policy, permission, API endpoint, seeded role, table or migration. The `ar`/`en` executions exercise server-side authorization; they are **not** evidence of visual RTL/LTR, responsive, keyboard or screen-reader acceptance.

## Application impact assessment
- **Backend:** Test-only coverage of inherited role union, global user DENY and inactive fail-closed behavior; no production code change.
- **Dashboard:** Existing authenticated routes and Sidebar server projections exercised; no view or layout modification. Independent role-based staging browser, AR/EN, RTL/LTR, responsive and accessibility acceptance remains outstanding.
- **Customer:** **Not affected** by this implementation; no mobile code, public Customer API, login, payload or deep-link change. Wholesale financial account behavior remains under separate canonical tests.
- **Driver:** **Not affected**; no Driver API, assignment, GPS, authorization or delivery status change. B2C fulfillment remains Driver only.
- **Van:** **Not affected**; no Van API, assignment, visit or settlement change. B2B fulfillment remains Van only.

Because no mobile behavior was changed, no new V2 mobile implementation issue is introduced. This does not waive mobile app contract regression requirements should future changes affect them.

## Completion / safe handoff
- Implementation branch: `test/2925-v2-exported-multirole-sod-audit`; one PR to `Foodex_V2` only.
- Exact-head Repository Policy, FOODEX V2 Isolation, Localization, Laravel tests, static analysis and real MySQL/Redis install/upgrade/recovery acceptance must succeed before merging.
- **Leave #2925 OPEN** even after successful test-only merge. Closure requires authorized, privacy-protected matching of historical row to current account, effective global/store permissions and override audit, actual SoD/entitlement/readiness verification, live isolated V2 browser role checks, AR/EN/RTL/LTR/a11y evidence, and explicit acceptance from the authorized owner.
- No main push/PR/merge, release tag, ZIP publication, cPanel changes or production deployment is authorized.
