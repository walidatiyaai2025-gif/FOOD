# V2 #2816 / P080 — multi-role selection, registry and advisory conflict evidence

**Source**: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P080; parent #2503; integration target `Foodex_V2` only.

## Source-scope acceptance
The canonical Administration → Security & Users → Users → Manage modal already supports multiple global roles and per-Retail-store roles (checkboxes), searchable available role registry, effective-role summary and separation-of-duties risk warning. The authoritative mutation remains `SecurityController::updateUserRoles` → `RbacManager::replaceUserRoles`, which atomically validates active scope-correct roles, actor grant authority and store boundaries, handles audit, and relies on runtime action authorization. We **reuse** rather than create another Registry/RBAC or Sidebar policy.

P080 found one actual live UI regression: `_security-role-assignment-picker.blade.php` manually applied `e(json_encode(...))` within Blade's already escaped `{{ ... }}`. This caused HTML entities to be escaped twice (`&amp;quot;`), so `dataset.roleRiskPairs` / `roleRiskMessages` / `rolePermissionSummary` were invalid JSON to the browser. The existing JS `parseJson` silently fell back to empty data: advisory conflict warnings and registry-derived permission summaries could disappear. Remove redundant manual `e()` while retaining Blade's ordinary single HTML escaping (no raw HTML or unescaped script insertion). Config `job_roles.risk_pairs` remains canonical. The procurement buyer + approver pair is **advisory** at assignment time; actual maker/checker and finance segregation is enforced at protected workflow actions, not via a UI warning.

## Placement / UX
- Existing canonical route: `admin.security.index?section=users`; form POST equivalent: `PUT admin.security.users.roles`; no new page, route or menu
- Dashboard user management modal remains responsive with searchable role options, checkboxes, a role summary and an `aria-live` status; localizable AR RTL / EN LTR text and keyboard/touch controls retained
- Browser decodes attribute HTML entities once; decoded JSON is valid and localized in AR and EN
- Multi-role global + B2C store submission preserves independent tenant/store scope and emits audited assignment state; malicious or mismatched scope fails before database mutation
- Unavailable/grant-locked roles remain unmodifiable; advisory preview is not an authorization grant

## Cross-app impact
| Surface | Impact | Proof |
|---|---|---|
| Backend (Laravel) | Tests only | Canonical RbacManager + role assignment endpoint unchanged; tests exercise multiple global/store grants, 403, cross-store denial and atomic validation |
| Dashboard | Changed | Fix JSON attribute escaping in existing role picker; no route/navigation rewrite |
| Customer Flutter | Not affected | No mobile API v1 payload, token, login, customer checkout, order or notification contract changed |
| Driver Flutter | Not affected | No mobile API v1, B2C driver assignment or live delivery status changes |
| Van Flutter | Not affected | No mobile API v1, B2B van-only routing/visits contract changes |

## Automated coverage
`backend/tests/Feature/V2MultiRoleRegistryConflictContractTest.php` validates:
1. AR/EN role registry and advisory conflict JSON survives exactly one browser HTML entity-decode; actual procurement maker/checker code pair and localized warning retained; role preview payload remains parsable
2. Multi-global-role + exact single-retail-store assignment persists both role grants and scoped role while retaining store2 denial and audit
3. Forged B2B store scope fails atomically without granting valid first role, and roleless actor gets HTTP 403 without side effects

Run Laravel/Pint/PHPStan, UI/static and AR/EN gates, real MySQL/Redis acceptance and the final Required CI Gate against **the exact PR HEAD**. Store links and head SHA in issue/PR only after runs complete. The browser/device/UAT acceptance is deferred to the final V2 program per #2503 owner decision.

**Boundary**: no merge into `main`, no release tag, no production/cPanel deployment.
