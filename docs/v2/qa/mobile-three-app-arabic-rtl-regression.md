# V2 #3373 — Three-app bundled Arabic RTL widget regression

Source: P112/P145 / MOB-ALL02. Parent #2741, master #2503. Integration base: `Foodex_V2` **only**.

## Reproduced test-coverage gap

Before #3373, Customer and Driver localization widget suites proved English/LTR and Arabic *remote override* text, but not bundled/default Arabic RTL. Van validated Arabic and English label translations without checking the real login Scaffold's Directionality. The shared localization quality workflow executes these real Flutter widget tests for mobile changes. An opposite-locale label regression could previously go undetected by these specific tests.

## Exact scoped implementation

- `apps/customer_app/test/localization_test.dart`: launch the real Customer diagnostics route with explicit `Locale('ar')`, assert native Arabic title, absence of the English title, locale and the nearest Scaffold ancestor's RTL Directionality.
- `apps/driver_app/test/localization_test.dart`: launch the existing unauthenticated Driver login with explicit `Locale('ar')`, assert native Arabic title, absence of the English title, locale and RTL Directionality.
- `apps/van_app/test/localization_test.dart`: for both native AR and EN login fixtures, assert the Directionality ancestor of the stable `van-customer-parity-login` Scaffold is RTL and LTR respectively; existing labels and negative language assertions remain.
- Preserve all existing English/LTR, bundled fallback and remote override tests. No runtime implementation, route, backend authorization, API, database, or schema change.

## Independent app and permission impact

| Surface | Impact |
|---|---|
| Customer | Flutter localization **test only**; tests the existing diagnostics route. No Customer API change. |
| Driver | Flutter localization **test only**; tests existing unauthenticated login. No assignment or B2C-only authority change. |
| Van | Flutter localization **test only**; tests existing Van login. No B2B-only custody, dispatch or auth change. |
| Backend / Dashboard | No source, route, permission, translated string or UI change. |

## Acceptance checks

Local Flutter SDK is not available in this session; do **not** treat the pushed test edits as verified. On the final exact PR head:

```bash
cd apps/customer_app && flutter test test/localization_test.dart
cd ../driver_app && flutter test test/localization_test.dart
cd ../van_app && flutter test test/localization_test.dart
```

Required GitHub gates: Repository Policy; FOODEX V2 Isolation; FOODEX Required CI including AR/EN localization runtime tests, per-app Flutter validation where selected, plus V2 three-app exact-head Android CI when triggered by app test paths. All applicable required jobs must finish SUCCESS before SHA-guarded merge to Foodex_V2 and closure of *this* child issue. Keep #2741, #2749 and #2503 OPEN: this child does **not** attest full accessible UX, end-to-end flows, staging-signed APKs, production readiness or all document nodes.

No `main`, tag, release, Play Store, cPanel, or production action.
