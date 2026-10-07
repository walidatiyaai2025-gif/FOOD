# UIUX v4.2 Recovery — Driver Evidence Manifest (#1039)

Mission: **#1034 — UIUX-V42-RECOVERY**  
Owner: **#1039 — Driver App full v4.2 compliance sweep**  
Canonical branch: `feat/1039-driver-v42-full-sweep`  
PR: **#1050**  
Source/test snapshot covered through: `058da20da3528a29dd8ff89065c419ce36807e77`

This manifest records owner-lane source and automated-test evidence. It **does not mark matrix rows PASS**. Real AR/EN runtime evidence and integrated review remain required by #1042/#1043.

| Row | Source evidence | Automated evidence | Owner-lane state | Runtime evidence still required |
|---|---|---|---|---|
| MD01 | `apps/driver_app/lib/features/delivery/active/driver_active_journey.dart` compact data-first cards; full-width Driver shell | `apps/driver_app/test/driver_active_journey_test.dart` — compact 320px card/header coverage | Implemented / pending integrated runtime | narrow + wide Driver runtime screenshots |
| MD02 | one-line horizontally scrollable date/today/all filters; one-line references; compact status; single green ellipsis actions in `driver_active_journey.dart` | `driver_active_journey_test.dart` — one-line filters at 320px; no-wrap reference + same-row green ellipsis; lifecycle action tests updated for overflow menu | Implemented / pending integrated runtime | AR/EN narrow/wide visual interaction evidence |
| MD03 | foreground 15s polling, resume refresh, background stop, stale/offline retention in `driver_active_journey.dart`; Home live refresh in `apps/driver_app/lib/navigation.dart` | `driver_active_journey_test.dart` — resume refresh + foreground polling/offline stale state; `navigation_test.dart` — Home server refresh/timer disposal | Implemented / pending integrated runtime | foreground/resume/offline runtime evidence |
| AD01 | exact visible `Driver App / تطبيق السائق` in `apps/driver_app/lib/features/auth/driver_login.dart` + `core/localization/driver_translations.dart` | `driver_login_persistence_test.dart` bilingual visible identity; `localization_test.dart`; `app_smoke_test.dart` | Implemented / pending integrated runtime | AR/EN login runtime evidence |
| AD02 | secure session persistence/biometric flow in Driver auth persistence/login | `driver_login_persistence_test.dart` — Remember Me flags and biometric saved-session authentication | Implemented / pending integrated runtime | real-device secure-storage/biometric evidence where capability exists |
| LD01 | foreground push stream in `core/push/firebase_push_service.dart`; authoritative Order/Assignment identity + View action in `app.dart` | `firebase_push_service_test.dart` — stable dedupe key + authoritative identity preference/fallback; `driver_push_deep_link_test.dart` — foreground alert UI | Implemented / pending integrated runtime | foreground real push alert with authoritative identity |
| LD02 | `app.dart::_openFromPush` routes exact `assignment_id`; Driver route passes focus ID into the journey; focused journey opens exact assignment detail | `driver_push_deep_link_test.dart` — Order #99 alert opens Assignment 42 while Assignment 43 / Order 100 remain absent; existing focus navigation tests in `driver_active_journey_test.dart` | Implemented / pending integrated runtime | real notification/deep-link exact-record evidence |
| LD03 | push alert event-key dedupe in `app.dart`; already-open notification refresh path in `features/notifications/driver_notification_page.dart` | `firebase_push_service_test.dart` stable assignment/order dedupe keys; `driver_notification_page_test.dart` already-open assignment refreshes instead of reopening | Implemented / pending integrated runtime | duplicate/already-open foreground runtime evidence |

## Acceptance boundary

- Green CI is necessary but not sufficient.
- These rows remain `OPEN` in the central requirement matrix until the required real runtime evidence is captured/reviewed and later independent gates converge.
- This manifest is intended as the #1039 source/test input to #1041 coverage and #1042 runtime verification; it must not be used to bypass either gate.
