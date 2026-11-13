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

The P278-specific integration was made after verifying peer P238/#2568 PR #3303 was **merged and released** into `Foodex_V2`. Preserve all upstream Sidebar and localization changes. This issue modifies **only the pre-existing Finance group AR/EN translated heading and the existing P279 bilingual heading assertion**, plus this P278 source trace. No fresh screen, duplicate regression, public API or Flutter changes.

One original issue branch `test/2608-v2-finance-treasury-group-projection` and PR #3307 target `Foodex_V2` only. All exact-final-head Repository Policy, V2 Isolation, Laravel/Pint/static/security, AR/EN localization, MySQL Redis install/upgrade/recovery and terminal `required-ci-gate` must pass before merge/closure. Record verified evidence in the Issue; manual owner/device/browser UAT is deferred to V2 final gates #2503/#2750 (`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`). No production deployment or main integration authorized.
