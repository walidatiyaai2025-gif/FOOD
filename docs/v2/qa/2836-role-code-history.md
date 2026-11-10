# V2 #2836 — Historical RBAC role identity / permanent code reservation

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P100; parent #2503. Branch: `feat/2836-v2-role-code-history` → `Foodex_V2` **only**. No main promotion, release tag, production change or server migration requested.

## Canonical model and business rule

- **Domain:** Security → Users, Roles & Permissions. **Existing route:** `admin.security.index?section=roles`; existing REST `GET/POST/PATCH/DELETE /api/v1/admin/security/roles`. **Actors:** `roles.manage` for mutation, `security.view` for audit/read. Reuse existing administration surface, no separate route, nav, form or ledger. Global/store role scope remains the server/RBAC authority.
- `Role::code` is a permanent historical identifier. A role's code and global/store scope cannot be reinterpreted by editing it or by deleting the row and recreating the same code. Existing `roles.code` unique constraint remains in force.
- The DELETE operation for **unassigned, non-system custom roles** is now **irreversible archival**, preserving the original `roles` row, identifier, code, name, scope and permission-role association. It sets `is_active=false` and `archived_at` once, under a DB row lock/transaction, and records before/after snapshots as `role.archived` in the existing audit logger.
- Archived records cannot be updated, cloned, reactivated or re-archived. Inactive roles which are **not** archived retain the existing ability to activate through reviewed edits. Existing assigned roles and system roles cannot be archived; the existing permission checks remain authoritative. Permission templates on **active** custom roles remain editable, audited as `role.updated`, and never retroactively alter immutable audit snapshots.
- Role-code identity changes are prohibited at the Eloquent update boundary. Direct raw SQL against the database remains a privileged operator capability and is **not** an approved RBAC administration path.
- Both the web Dashboard and REST API preserve their existing endpoints and HTTP statuses; the API role read model adds nullable ISO-8601 `archived_at` and the OpenAPI contract documents DELETE's archive semantics.

## Migration / legacy / backup compatibility

Additive `roles.archived_at` nullable timestamp, with no backfill or destructive rewrite of existing roles. Existing codes are preserved; previously deleted code strings cannot be reconstructed automatically from historical exports, and this change does not fabricate past records. For future operations, archive preserves code reuse protection by keeping the unique-key row.

The migration `down()` **refuses to drop** the archival column if any archived role exists, preventing silent unarchiving and code repurposing during rollback. Where no archived rows exist, rolling back drops only the new column, never role records. Older hard-deleted roles cannot be reconstructed automatically; a verified backup/recovery plan is required for any destructive rollback. Keep the isolated V2 DB and backup/recovery proof separate from production. Setup ZIP / Admin Update ZIP packaging remains governed by the normal FOODEX release contract and is not published from this branch.

## Security / localization / UI placement

Reuse → Extend: existing Security Center, Roles tab and `roles.manage` policy. The existing destructive-action confirmation and native button remain; their AR/EN text now states permanent archival and reserved code. Archived roles remain visible with a localized status badge and read-only explanation, with mutation controls omitted from the renderer. Backend checks continue to protect direct URLs, including unauthorized calls. No new screen/Sidebar/role authority, no cross-store grants or finance SoD bypass. The existing Admin shell/RTL-LTR/responsive card grid and keyboard focus are untouched.

Validation and audit failures do not delete or overwrite role history. Duplicate archive or changing the scope/code is rejected with localized field errors. Relevant mobile impact: **Customer:** no Flutter or customer API payload/state/permission workflow changes; **Driver:** no driver authorization/dispatch/GPS or app route change; **Van:** no Van/Wholesale dispatch or app contract change. Only the Management Security role API receives an additive `archived_at` field. Should follow-on scope prove actual mobile role-identity effects, create dedicated V2 MOBILE issues before parent acceptance.

## Automated acceptance targets

`backend/tests/Feature/V2RoleCodeHistoryTest.php` covers:
- Fresh DB migration/default nullable state.
- Real authenticated role DELETE 204 → preserved inactive role, permission relation, unique code and `role.archived` audit.
- Real role GET exposes `archived_at`; old code POST fails with 422; archived update/clone/delete fail 422, preventing reactivation.
- Unassigned scope reclassification and model-level code rename rejected; assigned/system role cannot be archived.
- Existing Security Roles UI shows archived read-only entries in AR/EN with no edit/delete action.
- Migration rollback refuses to erase non-empty archival state, while retaining archived role rows and code uniqueness.
- Existing full Laravel, MySQL/Redis installer/upgrade/recovery, localization, OpenAPI, static analysis, V2 isolation, Repository Policy, and all required exact-head CI must pass on the final branch before merge.

No runtime test result is claimed merely by authoring this matrix. Record exact-head workflow URLs, merge commit and isolated branch source proof in the Issue completion comment.
