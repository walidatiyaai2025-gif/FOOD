# V2 #2918 — Historical no-role RBAC account audit (Table 5, row 13)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native Table 5, data row 13. **Integration target:** `Foodex_V2` only. Parent #2503.

## Source fact and privacy boundary

The archived account-role export records **zero assigned RBAC roles** and **zero inherited RBAC permission codes** for this particular row at export time. It does **not** establish the current state of the live account, a Customer/Driver/Van identity, a safe deletion candidate, or the absence of direct permission overrides. Never copy the historical login, personally identifiable information, passwords, credentials, or production account IDs into code, CI logs or public Issue comments.

## Verified code contract and synthetic regression

`backend/tests/Feature/V2ExportedAccountRow13NoRoleTest.php` creates its own unrelated, fictional user via the Laravel factory and the canonical reference seeder. Both `ar` and `en` are exercised.

| Surface | Guard / expected outcome | Evidence boundary |
| --- | --- | --- |
| Dashboard / Security Center | No role, no inherited permission, no Platform Owner identity; `admin.security.index`, `admin.sidebar-access.roles` and Wholesale Orders direct HTTP fail closed with 403 | Synthetic no-role user only |
| Sidebar | No privileged role-access, Wholesale Orders or Retail Inventory navigation child | Canonical `AdminNavigation::groupsFor` projection |
| Wholesale / Retail tenant | No `orders.view` operational store scope without an actual assignment | `OperationalTenantScope` both channels |
| Driver | `app=driver` login and token-based wallet access fail closed without Driver authorization | No Driver model/assignment is fabricated |
| Van | `app=van` login and token-based visits access fail closed without Van authorization | No Van assignment is fabricated |
| Customer | No Customer API/identity or Flutter behavior changed; absence of Dashboard RBAC does **not** imply a Customer account is invalid | Live account type and eligibility **not established** |

No runtime controller, route, roles, migrations, Sidebar layout, locale key, permission policy or mobile application was changed. The tested Dashboard projection is read-only. AR/EN, RTL/LTR and responsive UI rely on the unchanged canonical navigation and components; this PR does not claim new visual UAT.

## Current-state acceptance gate (still open)

An authorized reviewer must compare the specific archived row against **current** user identity type, activation, roles, scoped store assignments, permission overrides (ALLOW and DENY), platform-owner registry, active Driver/Van assignments and Customer profile, using least-privilege **read-only** access. Record only sanitized pass/fail and source metadata in the Issue; do not record account identifiers in this repository. Evaluate linked Customer / Driver / Van V2 issues only when a real runtime/API impact is established. Do not delete, disable, reassign, or elevate the live identity without independent owner approval and a distinct reviewed corrective action.

Required before integration: Repository Policy, FOODEX V2 Isolation Gate, full FOODEX Required CI Gate (including Laravel/security/localization) **green on the exact final PR head**. This test slice is not full historical/live-account acceptance. Keep #2918 OPEN until the authorized reconciliation is evidenced. Never merge to `main`, deploy, publish tags, produce production artifacts or change production users.
