# V2 #2649 / P319 — App Preview canonical Sidebar and HTTP authority

## Source / placement
- Original owner source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P319, **معاينة التطبيق**.
- Parent: #2503; integration base: Foodex_V2 only.
- Domain / persona: Applications & Restaurant; B2B_ADMIN and B2C_STORE_ADMIN only when the canonical effective permission and channel/store scope permit.
- Navigation type: SIDEBAR, group `applications`, item `app_preview`. Existing AdminNavigation projection; never add an independently authorized menu.
- Route/renderer: GET `admin.app-preview.index` → AppPreviewController@index → `admin.app-preview`. The existing Preview Center provides Guest/Customer/Driver selection, Draft/Published configuration, AR/EN, device profiles, and embedded Flutter runtime. No new page.
- Directly related read-only routes: `admin.app-preview.storefront-configuration` and `admin.app-preview.targets`; session POST `admin.app-preview.sessions.store` is a separate, stronger impersonation action. Existing routes/middleware remain authoritative.

## RBAC, data scope and failure behavior
| Operation | Positive access | Negative/security boundary |
| --- | --- | --- |
| Sidebar and Preview Center GET | Effective `app_preview.view` plus Wholesale B2B access, or an assigned active B2C store with the same permission | Roleless/no-preview actor: no link, direct HTTP 403 |
| Guest Draft/Published config GET | Selected authorized channel/store and `app_preview.view` | Foreign Retail store: 403; mismatched Wholesale principal: 404; missing Draft: explicit `draft_unavailable` and never Published fallback |
| Customer target discovery | Exact permitted store and both `app_preview.view` and `app_preview.impersonate_customer` | No impersonation permission: 403; foreign store: 403 |
| Create a scoped impersonation session | Authenticated actor, target, channel/store and impersonation grant | Foreign-store create: 403 with no session/audit creation |
| Published preview | Authoritative current Published revision ID | Read-only; no publication or mutation triggered by Preview Center GET |

## Evidence and implementation decisions
- Reuse → Extend-tests. The existing production routes, AdminNavigation, StorefrontRevisionService, AppPreviewConfigurationController and AppPreviewSessionService meet the capability placement requirement. A second route/view or fake HTML runtime would introduce authority drift.
- New focused Laravel `V2AppPreviewSidebarHttpContractTest` covers one canonical link, AR/EN direction, read-only embedded preview, Draft-missing versus exact Published revision, retail A/B isolation on GET/target/session POST, zero created sessions on denial, and view-only versus impersonation permissions.
- Existing independent regressions: `V2AppPreviewSidebarMigrationTest` (#2728), `AppPreviewDashboardTest`, `AppPreviewGuestConfigurationTest`, `StorefrontPreviewConfigurationTest`, `AppPreviewDashboardBridgeTest`, `AppPreviewSessionTest`; reuse rather than copy their production implementations.
- Existing runtime contract: `docs/architecture/APP_PREVIEW_ARCHITECTURE.md` and `docs/quality/APP_PREVIEW_FINAL_ACCEPTANCE.md`. Runtime deployment URL/origin and visual/device-level final acceptance are not asserted as observed by these HTTP tests. Manual staging/browser UAT is deferred to final V2 acceptance gate #2750 per owner #2503.

## Customer / Driver / Van impact review
| App | Impact classification | Evidence |
| --- | --- | --- |
| Customer | No app runtime/API contract change | No Flutter, public Customer API, StorefrontRevisionService or Preview payload changes; only Laravel Dashboard HTTP tests and QA text. Existing Guest/authenticated Draft/Published regression remains authoritative. |
| Driver | No app runtime/API contract change | No Driver code, delivery/assignment API, session service or B2C driver role change. Existing Driver preview uses Retail B2C only, preserving B2B=Van-only. |
| Van | No app runtime/API contract change | No Van code, field-operation assignment/dispatch API or B2B lifecycle change; only Dashboard Preview Center access tests. |

No new mobile implementation Issue is required for this tests-and-evidence-only diff. This review does not claim mobile device testing occurred.

## Acceptance ledger
- [x] Root AGENTS.md, V2 isolation governance, master owner override #2503, current open PR/worker lease preflight reviewed.
- [x] Single claim and worker:active label before code; one independent issue branch from Foodex_V2.
- [x] Existing canonical authorized Sidebar and Preview Center reused; no new feature flags, grants, migrations, backend business logic or shared navigation edits.
- [ ] Laravel focused suite / AR-EN / negative authorization evidence verified on the exact PR HEAD.
- [ ] All applicable Required CI Gate, Repository Policy and V2 Isolation Gate successful at the exact PR HEAD.
- [ ] One PR merged only into Foodex_V2, merge SHA verified, Issue closed completed when source-scope checks are green.

Security: Never copy session credentials, PII, live server values or user private data to Issue/PR logs. No main integration, release, tags or production deployment.
