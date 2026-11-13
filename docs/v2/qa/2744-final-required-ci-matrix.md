# FOODEX V2 #2744 — G003 exact-head four-runtime CI matrix

**Source:** P112/P145, FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx. **Owner gate:** #2744 under #2503. **Integration:** Foodex_V2 only. **Status:** CI-scope implementation; not final V2 release or production certification.

## Reproduced gap and bounded remedy

The existing Required CI workflow chooses Backend, Customer, Driver and Van runners from `.ci/ci-map.json`, changed paths and branch rules. Normal backend-only/documentation diffs intentionally skip Flutter lanes, which is appropriate for routine scoped PRs but insufficient when certifying the eventual full isolated V2 baseline across all four runtimes.

The issue-owned branch `test/2744-v2-final-required-ci-matrix` now explicitly enables the **Backend, Customer, Driver, Van, Dashboard UI and Mobile UI** plan areas even for a test/docs-only diff. The existing `scripts/ci-plan.py` correctly derives `backend_dashboard_only=false` from that explicit branch rule. In the real `.github/workflows/required-ci-gate.yml`, all four validation jobs use those outputs and are dependencies of the terminal `required-ci-gate`. This change **does not change or weaken** the immutable Enterprise performance, Repository Policy, V2 isolation, PHPUnit/Laravel, security, localization, MySQL/Redis, or Flutter quality gates; it does not alter the shared workflow owned by #3160/PR #3171.

Regression tests in `scripts/tests/test_ci_plan.py` assert exact-branch routing, all four real job entries, non-skipped Customer/Driver/Van for G003, and negative control that ordinary backend-only V2 branches remain narrowly scoped.

## V2 applicability

- **Backend / Dashboard:** G003 verification affected; no API, controller, RBAC, Sidebar, UI or database changes.
- **Customer Flutter:** validation lane affected (full Flutter analysis/test); no runtime source, user auth, checkout or API changes.
- **Driver Flutter:** validation lane affected; no runtime source, role, delivery or API changes; B2C=DRIVER ONLY unchanged.
- **Van Flutter:** validation lane affected; no runtime source, stock, assignment or API changes; B2B=VAN ONLY unchanged.
- **Release:** no production version, APK signing, Setup ZIP, Update ZIP, tag, main deployment or cPanel changes.

## Acceptance and limits

1. The original one PR for #2744 must target `Foodex_V2`; GitHub Policy, V2 Isolation and Required CI must be terminal-success on its **exact final HEAD**. Check child Backend/Customer/Driver/Van/Localization/security/install-upgrade-recovery jobs rather than inferring pass from the top-level label. Skipped mobile validation on this G003 branch is a failure to satisfy this narrow contract.
2. Preserve performance baseline provenance. If the CI map change triggers Enterprise performance and the immutable baseline is non-comparable, **do not skip or alter comparison**; coordinate the existing #3160/PR #3171 owner and leave this PR draft/blocked until source-backed comparable evidence is available.
3. Full G003 closure remains gated on a **frozen final Foodex_V2 SHA**, complete relevant security/AR-EN/Flutter/Laravel/ERP tests and exact tested V2 artifacts; #2745–#2749 own signed ZIP and synchronized 3-APK package evidence. A green CI for this branch tests the matrix only, not a final release or owner UAT.
4. All acceptance is on isolated staging only. **NO** PR/merge to `main`, version/tag, APK publishing or production server modification.
