# V2 #2800 / P049 — actionable RBAC page-tree permission editor

Integration: **Foodex_V2 only**. Parent #2503. Source: FOODEX unified RBAC/ERP/Sidebar discussion, paragraph P049. No main, release, tag, cPanel or production.

## Canonical placement and authoritative write path

- Domain: Administration / Security & Access.
- Entity: custom role, its canonical \`permission_role\` grants and the resulting Sidebar projection.
- Authorized actor: effective \`roles.manage\` (Super Admin for unrestricted grants), no privilege escalations for lesser users.
- User goal: safely review and change the permissions behind the existing page tree.
- Workflow: privileged, infrequent, auditable form mutation.
- Existing parent / navigation: Administration Hub → Security → Role Access; canonical \`admin.sidebar-access.roles\` GET and \`admin.security.roles.update\` PATCH to \`SecurityController::updateRole\` and \`RbacManager::updateRole\`.
- Placement: **Extend** the existing role-tree editor; no new route, menu, component or permission engine.
- Pattern: Settings / Master Data with permission tree preview.
- Desktop / tablet / mobile web: existing responsive role workbench and shared FOODEX shell reused.
- AR/RTL / EN/LTR: existing \`security_permissions.change_reason\` and \`security_permissions.change_reason_hint\` keys in both translation catalogs reused.
- Permissions / states: custom editable role and allowed codes; protected system/archived roles remain read-only, roleless denied 403, server rejects unknown/elevated grants; full audit evidence for successful updates.

## Verified defect and fix

The existing \`sidebar-role-access.blade.php\` form submits PATCH to \`admin.security.roles.update\` but omitted \`change_reason\`. The backend \`SecurityController::validateRole\` requires \`change_reason\` with **8–500 characters**. Thus submitting the real UI failed request validation, even when all the permission checkboxes were valid.

The form now includes a **required, localized, labeled and keyboard-focusable textarea** with the same 8–500 length contract, an accessible description, and shared FOODEX tokens/responsive form sizing. Existing double-submit guard, canonical backend grant checks, audit and permission-tree preview remain intact. No security policy is loosened and no hidden direct-write route is added.

## Real acceptance regression

\`backend/tests/Feature/V2RoleTreeChangeReasonAcceptanceTest.php\` covers:
1. AR/RTL and EN/LTR render the real editor with required localized reason field.
2. Forged/legacy PATCH without reason fails validation and persists no permission.
3. Valid authenticated, approved custom-role role-tree PATCH actually modifies \`permission_role\` and records \`role.updated\` audit.
4. Control-plane system role remains read-only; forged PATCH cannot strip its permissions.
5. Roleless operator receives direct 403 for tree view and role PATCH.

## Impact by application

| Surface | Effect |
| --- | --- |
| Backend | Real \`RbacManager\` remains canonical. No controller, API or policy contract change; direct PATCH tests exercise it. |
| Dashboard | Existing security role editor receives a required auditable explanation input; real form save becomes reachable again. |
| Customer | No Flutter login, RBAC, order, price, store or API payload change; permissions remain enforced by server. |
| Driver | No Driver API/login/dispatch change; B2C Driver only remains unaffected. |
| Van | No Van API/visit/dispatch change; B2B Van only remains unaffected. |

## Additional readiness gates

- Required exact-final-head CI: Repository Policy, V2 Isolation, AR/EN, PHP/Pint/PHPStan/security/OpenAPI, Laravel backend tests and MySQL/Redis acceptance; terminal required-ci-gate SUCCESS.
- Visual/browser assessment in AR/EN on 320/360/390/412/430/768/1024/1280+ widths, focus and input errors, real role-change round trip and audit drill-down remain final V2 UX source gates.
- Extended multi-role, delegated grant, store versus global and user override matrices belong to #2748 and security-role issues; this correction does not close those upstream acceptance requirements.
- Do not close #2800 until the canonical source tests and applicable UX/security evidence are confirmed. Manual product UAT can be deferred to #2503 only after all automated/source acceptance is complete.

No Composer/Pint/Laravel local toolchain was executed through the GitHub Connector; GitHub CI evidence must be read separately. No production data or real account secrets used.
