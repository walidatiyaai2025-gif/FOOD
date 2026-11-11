# V2 #2924 — exported roleless-account audit (privacy-safe)

## Source boundary

- Parent: #2503; source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native table pointer **Table 5 (zero-based), data row 19**.
- The dated export records **no Dashboard RBAC assignment and zero inherited permissions** for that row. This is historical evidence only. No identity, email, credentials, raw account record or live assignment is stored here.
- The source row **does not establish the user's current account type** (Dashboard / Customer / Driver / Van), active/disabled state, current per-user ALLOW/DENY, Retail store roles, tenant, module entitlement, Sidebar output or session validity.
- Do not delete, disable, assign roles to, or otherwise change a real account based on a historical export. No production account was queried or mutated for this PR.

## Verified synthetic contract

| Dimension | Automated isolated V2 assertion | What is not certified |
| --- | --- | --- |
| No Dashboard roles | Synthetic active user has neither global `role_user` nor store-scoped `user_store_roles` assignments, with zero inherited permissions for B2B, B2C and globally | Present-day assignment/status of the actual exported identity |
| Tenant and channel isolation | `OperationalTenantScope::allowedStoreIds` for both channels is empty, with separate active Wholesale and Retail stores | Current real account tenant/store links and external roles |
| Sidebar and direct URL | `AdminNavigation::groupsFor` is empty; direct authenticated Security Center and B2B/B2C inventory requests fail closed | Real browser/sidebar/RTL/LTR/responsive/a11y user acceptance |
| Orphaned user ALLOW | Synthetic global platform and inventory ALLOW plus Retail store ALLOW without any role assignment do not yield effective grants, Sidebar or operational store scope | Historical/current user-specific overrides and approval/audit trail |
| Identity preservation | Roleless synthetic account remains active and persisted; tests neither assign permissions nor delete the user | Whether the actual identity belongs to a Customer, Driver, Van or other non-Dashboard account |
| Localization | All authority checks run with `ar` and `en`; neither locale changes authorization | Manual Arabic/English accessibility and device rendering |

Two Laravel Feature tests under `backend/tests/Feature/V2ExportedRolelessAccountRow19AuditTest.php` use only test-database synthetic identities. The tests exercise existing canonical User, OperationalTenantScope and AdminNavigation behavior, not a new permission/route/menu policy. No broad role grants, migrations, production user data, new APIs, or customer-facing UI were introduced.

## Backend, Dashboard and app impact classification

- **Backend:** Add synthetic, isolation-focused Feature regressions only. No runtime authorization semantics, schema, or public API changed.
- **Dashboard:** Existing authenticated Sidebar projection and direct routes tested fail closed for an unassigned user. No UI source or navigation changed. The historical account's actual Dashboard role status remains unverified.
- **Customer app:** **Not affected by this PR**: no Flutter, Customer API, identity issuance, checkout, wholesale price or notification contract changed. The historical roleless account may be a Customer; do not infer otherwise.
- **Driver app:** **Not affected by this PR**: no Flutter, dispatch, wallet, token, proof or delivery contract changed. B2C delivery remains Driver only. Historical account persona unverified.
- **Van app:** **Not affected by this PR**: no Flutter, Van trips/visits, B2B assignments, offline or settlement contract changed. B2B fulfillment remains Van only. Historical persona unverified.

No new mobile implementation issue is justified by test-only changes. Actual account-type classification, if it reveals an affected app or shared API contract, belongs in the appropriate linked V2 mobile issue and must be handled before source-scope closure.

## Outstanding real source-scope verification

An authorized reviewer must securely map historical source row 19 to the current account using read-only least-privilege access, without posting identifying values to GitHub, then attest: active state, account persona, actual global/store roles, tenant and store/channel scope, user override effects, effective permission and Sidebar projection, and any cross-domain SoD/finance exposure. No old export should be used to change a live account without approved, reviewed V2 code/config and audit.

Owner's #2503 decision defers **manual** UAT/device/staging acceptance to the final master gate, not missing source-scope implementation. Keep #2924 **OPEN** if the actual account audit remains incomplete; do not mark it `completed` merely for a green synthetic test.

## Delivery / safety

- Issue-scoped branch: `test/2924-v2-roleless-account-isolation-audit`; **one PR into `Foodex_V2` exclusively**.
- Require Repository Policy, FOODEX V2 Isolation Gate and full Required CI on exact head (Laravel, Pint, PHPStan, localization, security and MySQL/Redis install/upgrade/recovery where applicable) before merging.
- No V2 to `main`, no production data access or mutation, no release, tags, APK publication, cPanel update or deployment.
