# V2 #3440 — Web Installer AR/EN, RTL/LTR and language-preserving wizard

Parent G004 #2745, P112 #2846, master #2503. Target **Foodex_V2 only**. This is an atomic Web Installer localization and accessibility bugfix; it does not make a Setup ZIP, Release or an existing site production-ready.

## Exact source/UX gap

Before this change, `install/index.blade.php` used a constant `lang="ar" dir="rtl"`, displayed Arabic instructions beside English-only field/step titles, and rendered the 15 `InstallerChecklist` labels without localization. `APP_LOCALE=en` at step 6 did not produce a consistent English/LTR wizard. Installer Workflow errors and ad-hoc validation were English even under Arabic rendering. This conflicts with `docs/quality/LOCALIZATION_CONTRACT.md`.

**Placement:** Reuse/extend existing token-protected `/install` wizard, **no new route, Sidebar, admin surface or business action**. Domain Installer; persona first-run authorized installer; entity installation state; user goal first configuration; placement existing wizard steps; data inputs unchanged; workflow Next/Finish unchanged. Permission/auth/token/order/installed-lock/secret protection remains controlled entirely by existing backend. Language toggles must have no side effects on .env or install-progress.

## Delivered language contract

- `?lang=ar` or `?lang=en` is a validated display-only override across the step navigation and form POST redirect. Invalid `lang`, including arrays, falls back to saved `APP_LOCALE` when it is supported or safe Arabic otherwise. The selected platform `APP_LOCALE` is the default without an explicit display override.
- The HTML `lang` and `dir`, step progress, all 15 canonical step titles/descriptions, notices, field labels, safe options and action labels follow the active locale using parity-matched `backend/lang/ar|en/installer.php` keys. Internal step slugs, input names, enums and technical tokens remain unchanged.
- Installer requirement, MySQL, storage, queue, migration, seed, health, version and administrative errors use bilingual Workflow messages; validation uses installer-scoped localized attributes and rules, without globally modifying validation behavior for other Laravel surfaces.
- Language toggle is a keyboard-reachable native link with a labelled language navigation; focus-visible styles, 44px input/link/action targets and 16px field text; on narrow viewports step navigation is horizontally scrollable **within its container**, not a full-screen overflow. Exact AR/RTL and EN/LTR step/labels render from the same canonical view.
- No account, credential, signature, backend API contract, schema, Finance, RBAC, Van/Driver/Customer code or production data changes. No runtime setup calls are executed merely to switch the displayed language.

## Acceptance / proof

Focused HTTP/Laravel tests:
`php artisan test --filter=V2InstallerLocaleLayoutParityTest`
`php artisan test --filter=InstallerWorkflowTest`
`php artisan test --filter=V2InstallerCompletedDatabaseStepReplayTest`

Negative coverage: invalid language, forged POST token (419), state remains unchanged on validation/migration failure, step-by-step redirect language retention, stored APP_LOCALE remains unchanged after UI language selection. Existing install/security tests must remain green. All required Repository Policy, FOODEX V2 Isolation, AR/EN localization and FOODEX Required CI Gate must succeed **on final PR head** before integrating into Foodex_V2. The parent release/installer gates remain OPEN for signed Setup/Update ZIP, MySQL upgrade/data preservation and deployment UAT; no production deployment authorized. Manual physical viewport/keyboard audit is deferred to V2 final #2503 only after automated acceptance.

| Surface | Impact |
| --- | --- |
| Backend | Installer-only locale resolver and Workflow validation/error presentation; no changes to installer authorization, DB or API |
| Dashboard | Existing `/install` wizard localized, no new route/menu/role |
| Customer | No mobile contract, API or Flutter changes |
| Driver | No app login/dispatch/API or Flutter changes; B2C DRIVER ONLY unchanged |
| Van | No app login/assignment/API or Flutter changes; B2B VAN ONLY unchanged |

The original current platform language persists as a site setting; a GET language choice is not a platform configuration mutation.
