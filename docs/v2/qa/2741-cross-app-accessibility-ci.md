# V2 #2741 — three-app bilingual accessibility CI evidence (MOB-ALL02)

**Integration:** Foodex_V2 only; parent #2503 / G008 #2749. This scoped change adds genuine Flutter widget interaction evidence. It does not authorize production distribution or certify the entire source requirement.

## Canonical placement and reuse

The existing Customer diagnostics route, Driver sign-in screen and Van sign-in screen are reused directly. No new route, menu, sign-in implementation, API, Dart localization catalogue, RBAC permission or session capability is introduced. Prior child #3373 / merged PR #3379 already tests bundled RTL text; this issue independently checks actual focusable control accessibility semantics and form interactions instead of repeating translated heading assertions.

| App | Existing surface | Direct regression |
| --- | --- | --- |
| Customer | `FoodexCustomerApp` diagnostics route | AR/EN localized note InputDecoration + accessible semantics, focus/editable state, export action semantic label and enabled status |
| Driver | `FoodexDriverApp` unauthenticated login | AR/EN email/password semantic labeling, focus/input and sign-in action label; no HTTP sign-in |
| Van | `VanLoginScreen` with fake repository | AR/EN email accessible hint, focus, password masking and required-email validation after tapping Sign in; fake repository throws on any attempted credential POST |

The tests use `tester.ensureSemantics()`, real app widgets and deterministic local inputs. They do not use real credentials, production HTTP, push, location or deployment. Existing AR/EN and LTR/RTL regression tests continue to run. The two localized variants must both pass.

## CI integration

The existing `.github/workflows/v2-mobile-trio-ci.yml` exact-head Android matrix now runs, for EACH of Customer, Driver and Van, both `test/localization_test.dart` and `test/v2_accessibility_e2e_test.dart` **before** building validation APKs. A failed semantics/focus/locale interaction fails the app matrix and blocks the three-app provenance job; CI does not replace test failure with skipped build evidence.

Commands from each app root:

```bash
flutter pub get
flutter test test/localization_test.dart --reporter expanded
flutter test test/v2_accessibility_e2e_test.dart --reporter expanded
```

These are reproducible commands, not assertions of success until completed GitHub jobs or local execution establish their outcome.

## Security and application impact

- **Backend/API:** no controller, route, permission, token, ledger, database or business state delta.
- **Dashboard:** no view, navigation, locale or authorization delta.
- **Customer:** diagnostics view tested; B2B/B2C customer role and store context unchanged.
- **Driver:** login tested without network; B2C Driver-only dispatch unchanged.
- **Van:** login tested with a rejecting offline fake repository; B2B Van-only dispatch and consignment unchanged.
- **Privacy:** tests use synthetic email/note and never persist auth secrets.
- **Accessibility:** selected-locale field meaning, real focus/editing and actionable control labels are asserted. Broader screen-reader traversal, touch-target audits, high text-scale, physical device E2E and all screens remain unverified outside these entry-point tests.

## Acceptance remaining

The existing trio workflow produces **nonproduction/untrusted-signed Android validation APKs**, and its own provenance job truthfully identifies them as such; it is NOT evidence of authentic publisher-signed APKs. The master G008 release gate still needs a frozen exact V2 SHA, trusted publisher signatures and verified APK/provenance for all three apps, platform-wide accessible device/user journeys, integrated Backend/Flutter exact-head tests and Setup/Update ZIP acceptance. Until those facts are independently verified, keep **#2741 open** even if this scoped PR's exact-head CI is green.

No main merge, tags, releases, store submission, cPanel or production deployment.
