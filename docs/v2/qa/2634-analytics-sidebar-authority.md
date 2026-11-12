# V2 #2634 — P304 Reports & BI Sidebar authority

## Source, owner and integration
- Source requirement: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P304 (التقارير والتحليلات).
- Parent: #2503; isolated base: `Foodex_V2` only. No main, release, tag or production deployment.
- Implementation branch: `test/2634-v2-analytics-sidebar-authority`.
- Reuses the existing `AdminNavigation::groupsFor()` `analytics` group and existing server-authorized report routes. No new navigation branch, controller, API, database table, permission, or UI primitive is introduced.

## Placement and discoverability decision
| Field | Decision |
| --- | --- |
| Domain / entity | Reports & BI / authorized report workspaces |
| Role | B2B_ADMIN; scoped B2C_STORE_ADMIN; others only when the actual permission and tenant permit |
| User goal | Discover a real reporting surface and open it without 403 or cross-tenant exposure |
| Workflow type / frequency / data density | Repeated reporting and drill-down; existing reports center and channel workspaces |
| Existing parent / route | Dashboard `analytics` group; `admin.reports.index`, `admin.b2c.module`, `admin.b2b.module`, `admin.erp.bi` |
| Placement / pattern | **Reuse** of canonical Sidebar business group and reports workspaces; no new screen justified |
| Navigation type / owner / entry point | `SIDEBAR`, existing `AdminNavigation`, analytics group |
| Permissions / scopes | `reports.view` for reports; store-scoped Retail; Wholesale channel guard; ERP BI manifest/entitlement/readiness |
| Navigation impact | None; test actual existing projection and authority |
| Desktop / tablet / mobile Web | Existing Dashboard shell / responsive sidebar; unchanged |
| RTL / LTR | Existing `admin.nav_groups.analytics` translations (AR: التقارير والتحليلات; EN: Analytics) and page direction |
| States | Authorized ready, store denied, roleless, explicit permission deny, BI uncertified |
| Back path / fallback | Existing Dashboard sidebar and current reports workspace, not a dead direct URL |

## Server-authority acceptance contract
Canonical implementation: `backend/app/Support/AdminNavigation.php`, the Admin routes/controllers and their existing authorization guards. The Sidebar is a projection, not a second grant mechanism. The permission, channel, store and enterprise-module checks remain authoritative on each HTTP request.

The regression test `backend/tests/Feature/V2AnalyticsSidebarGroupAuthorityTest.php` covers:

1. Wholesale actor: AR/EN analytics translation, registered destinations, visible item HTTP success, and no Retail reporting link.
2. Retail store admin: link to **the assigned store only**, own-store HTTP allowed, foreign-store concealed as 404, Wholesale reports and platform ERP BI forbidden.
3. Scoped per-user explicit `reports.view` DENY overrides a role allow even if a persisted Sidebar visibility flag requests showing the link; role permission grant is preserved.
4. Enterprise BI operational readiness: uncertified `enterprise_bi` does not become an operational Sidebar link; its direct HTTP path remains forbidden even when other reports are available.
5. Roleless user and Retail STORE_STAFF without reporting permission: no reporting projection and direct reports route forbidden.

Permissions are not inferred from rendered labels; each route remains server checked. No changes were made to other workers' reports, ERP, BI, readiness or navigation implementation files.

## Customer / Driver / Van impact classification
| App | API contract impact | Flutter/runtime impact | Reason |
| --- | --- | --- | --- |
| Customer | None | None | Dashboard-only navigation regression, existing report routes unchanged |
| Driver | None | None | No dispatch/collections/status/API change |
| Van | None | None | No B2B route/visit/territory or data contract change |

No app-specific V2 mobile issue is created because this change does not modify application behavior. If a later runtime fix changes the shared API, reevaluate all three and link the corresponding V2 mobile issues.

## Verification and remaining acceptance
- Run focused Laravel test: `cd backend && php artisan test --filter=V2AnalyticsSidebarGroupAuthorityTest`.
- CI required on exact PR head: Repository Policy, FOODEX V2 Isolation, PHP/Pint/PHPStan/Security/OpenAPI fast-fail, AR/EN localization, Laravel tests, MySQL/Redis acceptance, final `required-ci-gate`.
- PR must target `Foodex_V2` exclusively. No merge on pending/failing/obsolete-head checks.
- Browser/responsive 320–1280 px and accessibility/visual parity cannot be certified by the PHP tests alone. If independent UI acceptance remains missing, keep the parent issue OPEN and document that limitation; do not claim screenshots or live runtime testing that did not happen.
