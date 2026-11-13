# FOODEX V2 G007 — ERP/RBAC/Sidebar final acceptance evidence register

- **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, G007; P109; P144
- **Owner Issue:** #2748; master #2503
- **Isolation:** `Foodex_V2` ONLY. No `main`, tagging, deployment, cPanel or production.
- **Scope of this PR:** evidence contract and regression guard only; does **not** certify complete ERP/RBAC/Sidebar behavior.

## Why this gate remains open

G007 is a comprehensive final acceptance gate, not the success of one Laravel test, a merged bounded PR or a screenshot. Pending proof includes exact role/action allow/deny, inactive and multi-role actors, B2B Wholesale vs B2C Retail and foreign-store records, entitlement and operational readiness, finance segregation of duties, full ERP source journeys, true server-authorized Sidebar destinations, AR/EN RTL/LTR accessible Dashboard and mobile parity.

The machine-readable register `docs/v2/V2_G007_ACCEPTANCE_MATRIX.json` enumerates the source domains and owning leaf issues. Every entry starts `pending`, not `verified`; this is deliberate. A worker may mark an entry `verified` only with source SHA and repository-hosted review, test, CI or artifact evidence. The PHPUnit regression refuses a final acceptance mark if any entry or Customer, Driver or Van classification remains unverified or any required exact-head check lacks matching successful evidence.

The register is *not* an authorization mechanism or substitute for runtime end-to-end tests. Status remains `accepted=false` even if this evidence-contract PR passes CI.

## Non-overlap and work ownership

This is an issue-owned additive set of three files only:
- `docs/v2/V2_G007_ACCEPTANCE_MATRIX.json`
- `backend/tests/Feature/V2G007AcceptanceEvidenceContractTest.php`
- `docs/v2/qa/2748-g007-acceptance-evidence.md`

No changes to live Laravel routes, permissions, Sidebar visibility, finance state transitions, existing CI workflows, customer/driver/van Flutter apps or other workers' files. This means no new public API/mobile runtime impact from this PR. **Separate G007 completion still requires testing each app and linking its existing mobile V2 issue.**

## Verification

Run `cd backend && php artisan test --filter=V2G007AcceptanceEvidenceContractTest`, Laravel/Pint/static-analysis and all exact-final-head required GitHub Actions checks. Inspect Repository Policy, FOODEX V2 Isolation and FOODEX Required CI including its child jobs; never claim success from an earlier SHA. The current shared Enterprise Performance baseline source review is independently tracked in #3160 / original PR #3171: no bypass, substitute, or blanket approval is introduced here.

**Handoff acceptance:** If CI is red, repair only this branch/PR. Even if this bounded PR is merged with all required checks green, **keep #2748 and #2503 OPEN** until all G007 journeys, app parity and security evidence actually exist, and owner-controlled final UAT is satisfied. Manual browser/staging UAT remains deferred to V2 final under the master program; automated security and CI are not deferred.
