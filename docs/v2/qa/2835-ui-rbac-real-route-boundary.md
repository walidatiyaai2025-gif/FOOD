# V2 P099 — Security Center permission form → authoritative HTTP boundary

**Issue:** #2835 (source document P099; parent #2503).
**Isolation:** branch `test/2835-v2-ui-permission-real-route-boundary` → PR base **`Foodex_V2` only**. No `main`, release, APK or production deployment.
**Ownership:** new regression test and this evidence file only; no changes to shared controllers, navigation, database, Flutter or production behavior.

## Existing canonical flow (reused, not replaced)
- **Actor / entry:** Super Admin submits a real Security Center role-template form, `PATCH admin.security.roles.update` / `SecurityController::updateRole`.
- **Persistence authority:** `RbacManager::updateRole` validates scope, grant authority and built-in role immutability; updates `permission_role`; reconciles canonical RBAC; writes `role.updated` audit.
- **Impacted user:** active B2B global role-holder with an extra isolated custom role. Permission `roles.manage` controls both the **Sidebar Role Access** link (`AdminNavigation`, item `sidebar_role_access`) and direct `GET admin.sidebar-access.roles` (`SidebarManagementController::roleAccess` Gate).
- **Authoritative separation:** neither `admin_sidebar_role_visibility` nor a client-provided checkbox independently grants the HTTP permission. Read-only navigation is a projection; server policy remains authoritative.

## Executable negative/positive acceptance
`backend/tests/Feature/V2RolePermissionUiToHttpAuthorizationTest.php` uses `CoreReferenceSeeder` and `RefreshDatabase`; each AR and EN case is synthetic and isolated.

1. Before the custom-role grant, a B2B user lacks `roles.manage`, the Sidebar item is absent, and direct HTTP returns **403**.
2. Super Admin submits role-template `PATCH` with `roles.manage`. Permission pivot and audit are real; same user's Sidebar item appears and the protected Dashboard HTTP response becomes **200** with matching RTL/LTR markup.
3. Super Admin revokes the role permission via the same form. The direct URL reverts to **403** and the item disappears. No personal overrides are created, and both changes are audited.
4. A forged `PATCH` from a B2B admin without `roles.manage` returns **403**, changes no permissions and records no `role.updated` event.
5. Even a Super Admin cannot use that same form to mutate a system-owned built-in role; the validation error is returned and canonical grants/audit remain unchanged.

**Permission & tenant bounds:** Only global `roles.manage` on a synthetic B2B actor is exercised. The test does not widen channel/store scope and does not invoke financial workflows or maker/checker approval routes. Existing broader V2 tenant and finance tests remain authoritative; a fresh test is not evidence that every sensitive workflow has passed.

## Cross-app impact (assessed individually)
| App | Impact | Reason / proof |
| --- | --- | --- |
| Customer | Not affected | No customer app, public API, auth payload, checkout, order/finance contract or routes changed |
| Driver | Not affected | No driver app, assignment/delivery API, GPS, B2C dispatch or status transitions changed |
| Van | Not affected | No van app, field operations/visit API, B2B VAN dispatch or routing contracts changed |

The commit contains only a Dashboard/backend Feature test and this markdown document; no runtime/API changes requiring an app-specific V2 child issue.

## Remaining acceptance (do not fabricate)
- Run focused Laravel PHPUnit test, repository Pint/PHPStan/security, AR/EN and all exact-head required Actions, including MySQL/Redis deployment acceptance.
- Independently exercise the real Security Center form in authorized isolated V2 staging on desktop/mobile at AR/EN, RTL/LTR, keyboard and screen-reader navigation.
- Inspect the applicable maker/checker and entitlement/readiness journey separately; this isolated test proves only the precise `roles.manage` path, not all enterprise approvals.
- Issue closure only after P099 acceptance is evidenced. An isolated merge does not mean production rollout.

No endpoint, data migration, release, production ZIP, or actual user account is modified by this regression.
