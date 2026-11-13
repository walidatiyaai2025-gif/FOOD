# V2 #2629 / Sidebar P299 — Accrued Payable (المستحق المتراكم)

## Exact source and architecture
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P299, Finance & Treasury. Parent #2503.
- Domain/entity: Finance → supplier Accounts Payable accrual / liability posting, not Treasury bank payment.
- Actor: Finance payable maker and separate Finance/AP poster; scoped active Retail store and legal entity. Other authorized stores may use the same canonical service; cross-store queries remain forbidden.
- User goal: distinguish a saved **draft** obligation from a **posted/accrued** supplier liability. Review posted open accrual before allocating cash or calculating net due.
- Placement decision: **Reuse** existing authenticated Finance Workbench, `admin.erp.finance.index?tab=payables&store_id={authorized_store}`. Navigation: existing `erp_finance` / Finance business group → Payables tab; no new Sidebar, route, payment engine or dead link. `navigation_type=PAGE_TAB`, `pattern=Operational Workbench`, `frequency=regular`, `data_density=paginated grid + aging`, `active_context=store/legal entity`, `fallback=Finance Workbench`. Existing AR/EN, RTL/LTR, desktop/tablet/mobile web and a11y are reused unchanged.
- Exact read authority: effective `finance.view`, Finance module enabled and certified, `finance.ap.read` entitlement, authorized legal entity/store/channel and active identity. Direct HTTP denial is authoritative, not merely Sidebar visibility.
- Accrual creation: maker `finance.manage` + AP manage and GL manage rights; posting: independent checker with `finance.post` + `finance.ap.post` / `finance.gl.post` entitlement; self-post forbidden by `AccountsPayableService::post`, canonical GL and immutable audit. An unposted draft may remain visible in the documents audit list but MUST NOT enter posted aging/outstanding.
- No release, production, irreversible account mutation, schema change or increased role grant.

## Specific technical acceptance and no-duplication boundary

`backend/tests/Feature/V2AccountsPayablePageAuthorityTest.php::test_p299_posted_ap_accrual_only_enters_the_authorized_outstanding_read_model_after_separate_posting` extends the already-merged P287 authoritative page tests instead of duplicating test fixtures, and establishes:

1. Under an enabled/certified and legally entitled Finance context, two supplier AP drafts exist but the **posted-only accrued aging is empty** before posting; no journal created by drafts alone.
2. A distinct scoped finance poster posts exactly one draft, producing one balanced two-entry GL journal, an AP posting audit, and no cash allocations; repeated POST is idempotent. The other document stays draft.
3. The actual authenticated Finance Payables tab shows in **both AR/RTL and EN/LTR** one posted open accrual of KWD 100.000, excluding the draft from the accrued aging while retaining both documents for provenance.
4. Existing P287 tests remain authoritative for denied unrelated store, roleless, disabled module, explicit user DENY and AP-read entitlement; the real domain foundation tests remain authoritative for independent maker/checker and GL posting safeguards.

**Do not duplicate active #2630 / PR #3290 (P300):** It owns **net amount due after posted allocations/settlement and zero-balance exclusion**, not the P299 creation and initial GL recognition of an accrued liability. We do not edit its two issue-owned test/QA files or Finance controller/services, or its current worker lease.

## Backend / Dashboard / Customer / Driver / Van classification

- Backend: additive application-level regression against unchanged Finance canonical service; no migrations, endpoints, API responses, middleware or auth changes.
- Dashboard: existing Finance → Payables tab, localized direction and read-only accrual view; no UI/route/CSS changes. Existing responsive/keyboard/accessibility contracts retained. Manual real browser accessibility/UAT deferred to #2503/#2750, not claimed here.
- Customer app: **no effect**, no customer pricing/checkout/credit/HTTP or Flutter contract change.
- Driver app: **no effect**, B2C DRIVER ONLY and wallet/settlement API unaffected.
- Van app: **no effect**, B2B VAN ONLY and Van consignment/trip API unaffected.

## CI and deployment contract
Exactly one branch `test/2629-v2-accrued-payables-posting-authority`, one PR to `Foodex_V2` only. Required before merging: exact-final-head Repository Policy, FOODEX V2 Isolation Gate, full Required CI with Laravel/PHP syntax/Pint/PHPStan/security, AR/EN, MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate` SUCCESS; no waiver. The final technical leaf is closable after verified integration; owner manual UAT is **DEFERRED_TO_V2_FINAL** (parent #2503 / #2750). Do not touch `main`, release tags, production, cPanel or deploy.
