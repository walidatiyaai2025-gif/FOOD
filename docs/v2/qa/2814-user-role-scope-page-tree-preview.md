# V2 #2814 / P077 — user role and scope page tree preview

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph P077. Parent #2503. Isolated integration: `Foodex_V2` ONLY.

## Placement and navigation
- Domain: Administration → Security & Users → Users; entity: selected account's role assignments and candidate sidebar nodes.
- Actors: authenticated administrators with BOTH `security.view` and `users.roles.manage`; no unauthenticated, inactive or unauthorised access.
- User goal: choose global roles or a named authorized Retail store and see candidate page groups *before* submitting existing role assignments.
- Reuse → **Extend** existing role assignment Management modal (`admin.security.index?section=users`, partial `admin._security-role-assignment-picker`). No second Sidebar menu, no duplicate role engine.
- Work type: read-only projection. `GET admin.security.role-tree-preview` is an internal AJAX read endpoint (EXPLICIT_EXCEPTION); ordinary discoverability is the existing Security Center → User → Manage route. The actual assignment writer remains unchanged.
- Presentation: existing FOODEX form and tokens; groups in keyboard-accessible HTML `details`, responsive bounded scroll, logical RTL/LTR spacing, AR/EN strings, and accessible loading/error/empty messages.

## Authority and negative constraints
- Laravel `management.dashboard` and ERP middleware still wrap the endpoint. Controller checks both permission gates and active actor.
- Explicitly validate role IDs, deduplicate/limit input, enforce active/nonarchived status, correct global/store role scope, active B2C store and built-in role availability. Reject fabricated, mismatched or unauthorized candidate roles.
- Delegated actors cannot preview permission sets outside their own effective authority or the SUPER_ADMIN / PLATFORM_OWNER control-plane roles. Never use this AJAX response to authorize a link, a mutation, a user/role grant or data access.
- Projection reuses `AdminSidebarManager::roleMatrix` (registry, existing layout and saved hidden preferences), ignores disabled nodes and currently unready enterprise modules, and never mutates role, permission, user, store, audit or Sidebar records.
- This **does not** claim to be a simulation of the target user's full effective navigation: explicit user ALLOW/DENY, existing multi-role assignments, target-user status, store/channel eligibility, entitlement, tenant isolation, route and action authority all remain authoritative when the actual user accesses each HTTP route. The UI always states that boundary. The preview is a candidate tree, not an access or approval guarantee.
- No role, tenant, store, financial SoD, mobile authentication, delivery or dispatch policy was changed. B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged.

## Cross-application classification
| Surface | Impact | Evidence |
|---|---|---|
| Laravel Backend | Affected | New strictly read-only Dashboard GET endpoint, Security gates, input/role/store validation; no API v1 or mutating route change |
| Dashboard | Affected | Existing Security Center user role picker extended with asynchronous scope selector, role-change refresh and localized preview |
| Customer Flutter | Not affected | No Customer API response, role login, navigation, order, payment or push contract altered |
| Driver Flutter | Not affected | No Driver API, authentication, assignment, status or B2C dispatch contract altered |
| Van Flutter | Not affected | No Van API, authentication, routes/visits or B2B dispatch contract altered |

## Automated verification
- `backend/tests/Feature/V2UserRoleScopePageTreePreviewTest.php`: authorized read-only projection, unchanged RBAC/audit records, discoverable Security Center picker in AR/EN, roleless HTTP 403, fabricated/wrong-scope/inactive role 422, fabricated store 422, and Arabic JSON.
- PR must pass repository policy, FOODEX V2 Isolation Gate, Laravel/full backend and Required CI Gate on exact-final PR head. CI and source SHA are recorded in the PR/Issue when available, **not presumed** by this document.
- Manual browser/device/UAT acceptance is deferred per #2503 owner decision; no staging or production execution claimed.

**Release boundary:** no `main` merge, tag, production deployment, installer execution or cPanel changes without a separate explicit owner instruction.
