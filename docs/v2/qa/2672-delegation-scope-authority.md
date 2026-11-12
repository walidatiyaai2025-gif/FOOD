# V2 P342 — Temporary Delegations authorization and scope (Issue #2672)

## Authoritative requirement and route
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P342, parent #2503, Administration > Temporary Delegations.
- Reuse existing Enterprise Security Center in Administration Hub: `GET /admin/security/enterprise`; `POST /admin/security/enterprise/delegations`; `DELETE /admin/security/enterprise/delegations/{delegation}`; checker `POST /admin/security/enterprise/changes/{workflowRequest}/decision`.
- Existing bilingual responsive Blade card and the canonical `EnterpriseSecurityManager`/workflow engine remain the authoritative business implementation. No new Sidebar node, form, API endpoint, model, migration, or mobile code.

## Security problem and fix
1. Any actor with the broad Dashboard `security.view` gate previously received **all delegation rows**, including those from other enterprise organizations or stores, despite restricted `EnterpriseRoleDataScope`. Existing per-scope `scopeOptions` were not applied to this data projection.
2. Any globally permissioned delegate manager could submit direct cross-scope POST/DELETE and get a Laravel validation redirect from the service rather than a server authorization **403**. An in-scope actor who did not own a delegation could also reach the manager's validation rather than 403. Both violate P342 direct-ID security behavior.
3. The controller now restricts delegated rows by the same **active role/scoped permission projection** as displayed scope options; direct create/revoke checks `security.delegations.manage` on the exact target scope before submitting a maker/checker workflow; revoke also requires original delegator or Super Admin. The business service retains its independent scope, effective permission, no self-delegation, valid start/end, audit, independent checker and single-application enforcement.

## Access / negative-case matrix
| Actor and case | Expected |
|---|---|
| `security.view` with organization A only | GET succeeds, A delegation visible, B delegation hidden |
| Manager with scoped `security.delegations.manage`, org A | Own org request permitted (pending checker); org B POST/DELETE direct-ID returns 403 |
| In-scope manager, not original delegator | Cannot revoke someone else's delegation (403) |
| Independent `security.approve` reviewer | May approve valid pending change; maker may not self-approve |
| Roleless or inactive actor | GET/POST forbidden; no delegation created |
| End before start | Localized validation error, no effective delegation |
| Expired/revoked or inactive delegator | Existing `EnterpriseSecurityAccessResolver` denies effective delegated authority |

Tests: `backend/tests/Feature/V2DelegationScopeAuthorityTest.php` with actual HTTP AR/EN, RTL/LTR, multi-role, own/foreign scope and owner controls, pending/approved transitions, time validation, audit, unchanged foreign rows and no unauthorized workflow side effects. Regression of the shared resolver is already covered by `EnterpriseSecurityAdministrationTest`. Exact-head CI is required; test claims depend on observed GitHub outcomes.

## Cross-surface impact
- **Backend:** Existing authenticated Dashboard HTTP delegation list/create/revoke only.
- **Dashboard:** Existing Enterprise Security Center delegation card/list; server-scope-filtered projection and 403 on prohibited actions. Existing AR/EN/RTL/LTR responsive implementation unchanged.
- **Customer:** Not affected; no customer mobile API/permission/identity/commerce changes.
- **Driver:** Not affected; no driver app API/dispatch/collection changes, B2C remains Driver-only.
- **Van:** Not affected; no van app API/routes/consignment changes, B2B remains Van-only.
- No change to API payloads consumed by Customer/Driver/Van, Flutter build not required.

## Delivery boundary
PR `fix/2672-v2-delegation-scope-and-owner-authority` must target `Foodex_V2` only, with exact-head Repository Policy, V2 Isolation, full FOODEX Required CI (Laravel/Pint/PHPStan, AR/EN, MySQL/Redis install-upgrade-recovery) and terminal required-ci-gate all successful. Only then merge and close P342. Manual browser/device UAT is deferred to master #2503 / #2750. No main/release/tag/production.
