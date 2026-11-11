# V2 T122R23 — Executive Viewer Role (#2703)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, row `T122R23` (ERP role `EXECUTIVE_VIEWER`), parent #2503. **Integration base:** `Foodex_V2` only.

## Resolution: Reuse the canonical reporting surface

- **Actor:** assigned active `EXECUTIVE_VIEWER` (`global` scope, `tenant` business scope), **not** Super Admin and **not** Store Admin. Module prerequisite: `enterprise_bi` lifecycle enabled; operational BI UI readiness/entitlements remain independently enforced by their existing guards.
- **Entry/navigation:** reuse `Reports Center` under `Analytics`, canonical `admin.reports.index` (`/admin/reports`); report export uses `admin.reports.export`. Do not add a second role page, a new route or a duplicate sidebar item. Canonical `AdminNavigation` projects the existing `reports.view` route; `AdminSidebarManager` cannot grant authority.
- **Canonical permissions:** `reports.view`, `reports.export` only. Role's bundled grants are already seeded in `config/job_roles.php`; no modification to role definitions or stored assignments.
- **Corrected gap:** `admin.channels.b2b.global_roles` previously omitted `EXECUTIVE_VIEWER`. The existing `OperationalTenantScope::allowedStoreIds` consequently returned no B2B scope, so an otherwise entitled reporting viewer was forbidden on the real reports controller. The minimal config fix adds **only** `EXECUTIVE_VIEWER` to the B2B channel's supported global roles. The B2C global-role list is unchanged, preserving Retail store isolation. Module-disabled reports remain forbidden; the action does not bypass module, permission, cross-store or other direct route guards.
- **Report HTTP fail-closed correction:** the existing Reports Center index, lookup and export endpoints could validate a missing store before checking whether the actor had any authorized report store scope, producing HTTP 302 validation redirects for disabled-module assignments. `ReportController::assertReportScopeBeforeValidation` now checks effective `reports.view`/`reports.export` **before** filter validation using the effective global `User::hasPermission` grant **or** canonical `OperationalTenantScope` for store-scoped roles. This preserves B2B administrators before their principal store has been initialized as well as legitimate store-scoped Retail actors (covered by `V2SidebarAppearanceNonAuthorityTest`), while deeper channel/store, record and export checks remain authoritative. A denied user gets HTTP 403 and cannot reach report data or export audit side effects.
- **SoD:** reporting is read/export only. Finance approve/post, procurement manage, customer/order dispatch, identity mutation, treasury and driver operations are *not* implied. No workflow-maker/checker approval request is introduced.

## Automated acceptance

`backend/tests/Feature/V2ExecutiveViewerRoleAuthorityTest.php` verifies exact persisted role/permissions and AR/EN business presentation; disabled-module fail-closed direct HTTP and Sidebar; B2B report availability and AR/EN RTL/LTR actual GET; forbidden B2C and foreign Retail store reporting; no finance/treasury/procurement/order/driver/identity authorization; unauthorized user status request has no effects or audit record; disabling `enterprise_bi` removes access while preserving the historical role assignment. All data and users are synthetic test fixtures.

| Surface | Impact | Contract evidence |
|---|---|---|
| Laravel Backend / Dashboard | Affected: only the pre-existing B2B reporting route scope for EXECUTIVE_VIEWER | Role-specific test exercises canonical HTTP and RBAC/tenant gates. No new page; existing responsive/accessible Reports Center retained |
| Customer App | Not affected | No Customer endpoint, role, authentication token, B2B/B2C order, pricing, locale or message contract changed; admin-only global role channel mapping |
| Driver App | Not affected | No Driver status, retail-only dispatch, wallet, auth or mobile route changed |
| Van App | Not affected | No Van B2B-only dispatch, consignment, collection, visit or mobile auth route changed |

No new mobile issue is needed because this scope changes no mobile-facing API or Flutter code. The existing V2 mobile contract gates remain independent.

## Verification and limits

Required exact-head GitHub gates: Repository Policy, FOODEX V2 Isolation Gate, Localization AR/EN, Laravel Backend fast-fail/full tests, MySQL/Redis install-upgrade-recovery and `required-ci-gate`. If they fail, fix the **same** branch/PR and rerun. ERP BI readiness (including #2858) must not be silently certified by merely enabling the lifecycle in a test; this patch validates the legacy management Reports Center separately. Deferred staging/owner UAT stays in the final V2 gate under #2503; no claim of production deployment or live account effects is made. Only `Foodex_V2` may receive the PR, and no `main`/release/tag/production changes are authorized.
