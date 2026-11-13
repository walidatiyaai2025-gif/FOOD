# V2 #2923 — exported account row 18: privacy-safe RBAC classification audit

- **Evidence anchor:** 2026-11-09 original Word source, native Table 5, data row 18; parent #2503.
- **Historical observation:** the export records no assigned Dashboard RBAC roles and zero distinct inherited permissions. This is historical **source data**, not a trusted current-user state, an account-type verdict, or permission to change an account.
- **Confidentiality:** account name/handle, phone/email, credentials, tokens, and any live identifiers are deliberately excluded from this repository document and synthetic test. Keep actual account reconciliation in authorized, access-controlled evidence only.

## Canonical implementation reviewed

Existing Security Center users view exposes a server-side `assignment=roleless` filter and separate `is_platform_customer`-based presentation of `customer` versus `review`. Administrative permissions are determined by role/store assignment and effective policy, **not** by having a customer profile or appearing in a historical export. Reuse rather than create a new route or identity table.

The new `backend/tests/Feature/V2ExportedRolelessRow18ClassificationTest.php` adds two issue-scoped tests **without duplicating the row-19 orphan-override test**:

1. The *same synthetic* zero-role identity changes from the administrative **review** classification to **platform customer** classification in the existing authorized Security Center screen. Both English LTR and Arabic RTL response renderings are exercised. It remains without inherited/effective Dashboard permissions, grants, navigation, and Security/Sidebar HTTP access, and is not disabled or deleted.
2. A Retail store-scoped operator cannot list platform-wide unassigned identities via the Security Center even by specifying a foreign store; a separate roleless synthetic account cannot request Wholesale/Retail inventory through direct HTTP or acquire tenant inventory scope. Account and role assignments remain unchanged.

No actual exported account is created, identified, altered, archived, disabled, deleted or granted any role. Fixture-only reclassification does not represent a production action.

## Authority and scope matrix

| Boundary | Expected enforcement |
| --- | --- |
| Admin Security Center user inventory | Authorized global Security administrator only; unauthorized store-scoped Retail operator 403 |
| Roleless candidate's direct Security / Sidebar administration | 403; no administrative permission inferred from a customer classification |
| B2B vs B2C inventory | Denied 403/404 for a roleless actor, including foreign store IDs |
| Sidebar | No privileged group for a roleless account |
| Inherited versus explicit grants | No roles or per-user overrides created by classification |
| Records / audit | Only synthetic fixtures touched; no migration, live user or financial data mutation |
| AR/EN | Test covers real authorized LTR and RTL pages and denied direct routes |

## Customer, Driver and Van impact

- **Customer:** no customer identity endpoint, authentication token, store scope, pricing or Flutter code changed. An existing customer flag is only altered on a test fixture to prove it does not confer *Dashboard* rights.
- **Driver:** no driver assignment, RBAC/API contract, delivery route, notification or Flutter code changed.
- **Van:** no B2B Van assignment, route, consignment, stock or Flutter code changed. Existing B2B=VAN ONLY and B2C=DRIVER ONLY contracts preserved.

No public API or app change is introduced by this two-file test/evidence PR. If authorized reconciliation identifies a real app-side defect, create/link its separate V2 mobile issue before modifying the app.

## Evidence still required / no premature acceptance

An authorized read-only source-of-truth query must reconcile **the exact confidential historical account** against its current identity/account type, active flag, global and store roles, allow/deny overrides, store/channel/tenant, effective permissions and actual Sidebar response. The old document alone cannot establish its live state or authorize account mutation. No such current account attestation or manual UAT is claimed in this PR; track that remaining evidence against the issue and parent final V2 acceptance. If new source evidence identifies a bug, fix in a scoped V2 PR under the existing ownership/governance policy.

**Integration:** one `#2923` branch and PR with base `Foodex_V2` only; exact-head Repository Policy, V2 Isolation, Required CI (Laravel, security, AR/EN, applicable deployment checks) must all pass before merge. No `main`, release/tag or production deployment. Manual UAT: `DEFERRED_TO_V2_FINAL` per #2503; technical CI cannot be waived.
