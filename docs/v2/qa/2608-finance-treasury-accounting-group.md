# FOODEX V2 — #2608 / P278: Finance, Treasury and Accounting Sidebar

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, **P278** (`المالية والخزينة والمحاسبة`). Parent #2503, integration target **Foodex_V2 only**. No main release, tags or production.

## Functional ownership and placement

- **Domain / entity:** Finance, Treasury and Accounting; financial records and authorization context.
- **Actor:** authenticated active Finance/Treasury actor with effective `finance.view` and authorized store/legal-entity scope; grants remain independent by module, capability and user override.
- **Goal:** access existing, operationally ready Finance and Treasury screens from the canonical Sidebar business group.
- **Placement: Reuse** `AdminNavigation::groupsFor` group `finance` (`admin.nav_groups.finance`), plus the **separate existing** `receivables` group (P285/#2615), the existing `FinanceWorkbenchController` and Treasury workbench. No new screen, top-level entry, finance data model, API or security policy.
- **Navigation contract:** `SIDEBAR` business-domain group; `treasury_master` → `admin.treasury.master.index` (scoped `finance.view`), `erp_finance` → `admin.erp.finance.index` (Finance module ready/entitled), `erp_treasury` → `admin.erp.treasury.index` (Treasury module ready/entitled). Children appear only if separately authorized. AR/AP tabs have their own existing `receivables` group and legal-entity capability gate; no duplicate destination.
- **Human label:** source P278 uses Arabic `المالية والخزينة والمحاسبة`. Preserve a matched English `Finance, Treasury & Accounting` translation in `admin.nav_groups.finance` rather than adding a new key. Existing AR/EN Sidebar layout, `dir`, keyboard focus, responsive mobile web and back navigation are reused.

## Non-duplication and security evidence

- P279/#2609 already has `V2FinanceSidebarGroupAuthorityTest.php` proving an exact, unique 3-child group; Arabic/English translation and real HTTP 200, module disabled/uncertified, effective DENY, cross-store, roleless and inactive scenarios. Adjust the **existing** label assertion when P278 translation wording changes, instead of copying these tests.
- P280/#2610 already proves Finance Center direct HTTP permission and module/entitlement.
- P285/#2615 already proves independent, entitlement-projected AR/AP group and direct HTTP tab denials.
- P295/#2625 and P299/#2629/P300/#2630 cover detailed Treasury and payable actions, not new group permissions.
- Authorization is server-enforced, not granted by Sidebar visibility. Preserve wholesale/retail isolation, maker/checker, fail-closed readiness, and B2B=VAN only / B2C=DRIVER only.
- No business forms, data inputs, migrations, financial writes, endpoint payloads or permission definitions changed by the parent-group label alignment.

## Impact classification

| Surface | Impact |
| --- | --- |
| Backend/API | Existing server authority and routes reused; group label localization and automated assertion only. No API contract changes. |
| Dashboard | Existing Finance Sidebar heading aligned with P278 source in AR/RTL and EN/LTR; no new navigation behavior or layout primitives. |
| Customer Flutter | Not affected: no customer identity/credit/pricing/order REST or app change. |
| Driver Flutter | Not affected: no driver assignment, delivery, wallet or settlement contract change. |
| Van Flutter | Not affected: no Van territory, visit, route, consignment or dispatch contract change. |

## Delivery / exact-head gate

Keep all writes inside the single issue branch `test/2608-v2-finance-treasury-group-projection` and one PR to `Foodex_V2`. The locale catalogs are currently being changed by **another worker's existing P238/#2568 PR #3303**; DO NOT write them until that PR merges and its file fence releases. The only planned shared changes are two localized label values and their **existing** #2609 test assertion, with a GitHub revalidation immediately before touching them.

Require exact-final-head Repository Policy, FOODEX V2 Isolation, AR/EN localization, backend Laravel/Pint/static/security tests, MySQL Redis deployment acceptance and terminal `required-ci-gate` GREEN; only then merge and close this code-complete leaf. Manual visual/device/UAT acceptance remains deferred to V2 final gates #2503/#2750 (`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`). This document alone does not certify acceptance or green CI.
