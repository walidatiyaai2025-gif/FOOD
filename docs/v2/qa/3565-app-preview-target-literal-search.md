# FOODEX V2 #3565 — literal App Preview name search

Integration branch: Foodex_V2 ONLY; no main, tag, release, production, or cPanel. Parent #2503 still owns final device/staging/UX acceptance. Related independent SQL literal-search lanes #3545/#3561/#3563 are disjoint and untouched.

## Source discrepancy and scope
AppPreviewTargetService accepted a validated <=80-character q from the existing GET admin.app-preview.targets Dashboard endpoint but directly interpolated q as a SQL LIKE pattern in both Customer and Driver name discovery. A literal percent or underscore accidentally behaved as a wildcard and increased advisory target results. The downstream AppPreviewSessionService remains the authority for selecting/impersonating a permitted identity: no cross-store breach is claimed.

Use a private literalNamePattern helper in the service to escape !, % and _ with the explicit ! marker and parameter-bound name LIKE ? ESCAPE '!' on MySQL/MariaDB and SQLite. Preserve empty search behavior, 50-result bound, active-user filter, User/Driver role and channel rules, store tenancy and exact response shape. No session creation/revocation logic or mutation is changed.

## Acceptance evidence
Existing real Laravel RefreshDatabase AppPreviewDashboardBridgeTest fixture is extended with two HTTP tests:
- Customer directory: percent/underscore/exclamation literal names match exactly; false-positive lookalikes and foreign-store identities excluded, ordinary search still works
- Driver directory: matching B2C Driver user names, false positive and foreign-store rejection, literal escape marker handling, ordinary prefix term compatibility

No UI/route/payload/translation string changes. Dashboard current App Preview menu and target picker remain canonical and accessible; AR/EN labels, RTL/LTR and preview device runtime unaffected. Customer, Driver and Van Flutter/API schemas unchanged; no mobile code required. B2B fulfillment remains VAN ONLY and B2C remains DRIVER ONLY.

Before closure: exact-final-head GitHub Policy, V2 Isolation, Required CI including Laravel Backend, Pint/PHPStan/Composer security/OpenAPI, AR/EN and MySQL/Redis terminal SUCCESS. Branch/PR issue-scoped into Foodex_V2 only, QA/CI links and merge SHA logged. Close only atomic #3565 as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED, leave master #2503 OPEN.
