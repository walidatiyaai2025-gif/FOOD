# V2 #2778 / T028R01 — ERP and Field Operations authorized restoration

**Integration boundary:** `Foodex_V2` only. No `main`, production, tags, live migrations or release artifacts.

## Placement and capability

- **Domain / entity:** platform ERP workspaces and wholesale Field Operations / Van assignment workbench.
- **Persona:** global Operations role for positive admission; Retail-store-only operator, roleless and inactive users for negative admission.
- **User goal:** discover the real authorized ERP Center and field-operations workbench from business navigation, without using a direct URL to bypass role scope.
- **Reuse → Extend → Compose → New Screen:** **Reuse** the existing ERP Center route `admin.erp.index` and Field Operations route `admin.field-operations.assignments`; no replacement screen, duplicate route or new Sidebar entry.
- **Navigation owner / type:** ERP group / `SIDEBAR` for ERP Center; Field Operations group / `SIDEBAR` for B2B assignments. The existing role-based projection owns entry points; direct HTTP remains authoritative.
- **Parent / pattern:** ERP Center is the existing operational workbench and module/status directory; Field Operations assignments is an operational workbench, not a Retail store page.
- **Permissions and scope:** ERP Center requires active **global role** platform admission at HTTP boundary, matching `AdminNavigation::canAccessPlatformErp`; Field Operations assignments requires `drivers.b2b.view` at the existing controller boundary. The ERP module cards still separately enforce effective permission, entitlement, readiness and safe nonactionable degraded states.
- **State / language / viewport:** positive AR/RTL and EN/LTR ERP + assignments HTTP checks; negative roleless, inactive and store-only cases; existing shared Dashboard responsive/off-canvas layout and a11y components preserved (no HTML/CSS changes).
- **Fallback/back path:** existing business navigation and Dashboard / Action Center; no direct-url-only product capability.

## Source-level defect and targeted resolution

Previously, the Sidebar gated the ERP Center to active global-role staff, but the route controller accepted *any* authenticated user through dashboard admission. A Retail-store-only actor, who could be legitimately admitted to the Dashboard for Retail work, could directly request `/admin/erp` even though the platform ERP link was not presented. The ERP Center can assemble platform operational summaries and must not rely on a hidden menu as an access control.

`ErpCenterController::__invoke` now independently rejects actors outside the canonical `canAccessPlatformErp` platform role boundary with HTTP 403 **before** ERP module initialization, queries, rendering or other side effects. This is an authorization guard, not a new permission grant. No changes to workflow/card eligibility, entitlement checks, module readiness, or finance approval policy.

## Automated proof

`backend/tests/Feature/V2ErpFieldOperationsAuthorizedRestorationTest.php` covers:

1. Global `OPERATIONS`: actual ERP and B2B Assignments links with existing HTTP 200 in both Arabic/RTL and English/LTR.
2. Store-scoped `RETAIL_OPERATIONS`: keeps its own Retail orders permission, but no platform ERP or wholesale Assignments navigation and HTTP 403 for all direct URLs in both languages.
3. Roleless active actor: cannot use hidden ERP/Field Operations HTTP routes.
4. Inactive global Operations actor: neither ERP navigation nor its HTTP workbench is available.

The separate merged #3070 / PR #3079 already corrected Field Operations Assignments permission parity; this change does **not** duplicate the shared `AdminNavigation.php` / registry edits. Current issue-specific gate completion depends on exact-final-head Laravel, Pint, PHPStan, repository policy, V2 isolation, localization and Required CI.

## Cross-platform impact review

| Surface | Impact | Reason |
| --- | --- | --- |
| Backend / Laravel HTTP | **Affected** | Existing `GET /admin/erp` gains an explicit platform-actor admission guard; negative HTTP regressions added |
| Dashboard desktop / responsive web | **Affected (authorization only)** | Existing canonical ERP and Field Operations routes, navigation structure and shared RTL/LTR layout unchanged; server denies unauthorized direct ERP route |
| Customer Flutter | **Not affected** | No Customer API, Flutter routes, widgets, auth tokens or order/price/fulfillment contracts changed |
| Driver Flutter | **Not affected** | No Driver API, Flutter routes, assignment status or B2C delivery contract changed |
| Van Flutter | **Not affected** | No Van API, Flutter routes, Van dispatch or B2B fulfillment contract changed |

The ERP platform entry guard must not be mistaken for a new enterprise-module entitlement, or an authorization bypass. Effective per-user grant/deny projection across all Sidebar items is separately tracked under #2788 and #2758.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** (master #2503 / UX gate #2750); this document never asserts browser UAT, staging certification or live deployment. Close this technical leaf only if actual requirements and all exact-head CI gates are satisfied after isolated integration.
