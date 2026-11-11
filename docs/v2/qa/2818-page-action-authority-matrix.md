# V2 #2818 — P082 per-page action authorization

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P082; parent #2503. Integration target **Foodex_V2 only**.

## Canonical placement and authority

- Existing Dashboard module: B2B / Pricing (`B2bWorkspaceController::MODULE_PERMISSIONS['pricing'] = 'b2b.pricing.view'`); **Reuse**, no new Sidebar item, page or permissions.
- Existing API: `GET /api/v1/admin/b2b/prices` is a *View* action; `PUT /api/v1/admin/b2b/prices` is a *Manage/Edit* action.
- Previous API implementation incorrectly authorized both operations by `b2b.pricing.manage`, contrary to the existing Dashboard entry and distinct `b2b.pricing.view` permission. The fix requires `b2b.pricing.view` for GET while retaining `b2b.pricing.manage` for PUT. No response shape, database schema, navigation or permission grants change.
- Read remains **fail-closed** when price tiers are disabled (`409`); the existing canonical wholesale store and pricing-mode lookup are reused. `PUT` retains validation, B2B product ownership and AuditLogger.
- Explicit global *deny* for the View ability defeats role allow without silently stripping independent Manage; store-scoped Retail assignments do not authorize global Wholesale pricing. Cross-domain Admin Inventory, Reports and Security reads remain denied for a pricing-only principal.
- This does not authorize direct reads of individual customer prices, approve finance entries, dispatch orders or bypass existing module/tenant rules.

## Automated acceptance

`backend/tests/Feature/V2PageActionAuthorityMatrixTest.php` exercises:

| Actor | GET pricing (View) | PUT pricing (Manage) | Cross-domain reads |
|---|---|---|---|
| Pricing View only, AR and EN | 200 when tiers enabled | 403 | 403 |
| Pricing Manage only | 403 | 422 on intentionally invalid payload (authorized, no mutation) | not granted |
| Both with explicit View DENY | 403 | 422 on intentionally invalid payload | not granted |
| Retail store-only View grant | 403 | 403 | not granted |

Every test checks `b2b_price_rules` remains unchanged, and uses existing `RefreshDatabase` and `CoreReferenceSeeder`. Route checks run against actual Laravel HTTP endpoints, not merely Sidebar visibility. The invalid PUT is a deliberate validator probe, never a simulated successful business write.

**Other action classes in P082:** `catalog.create/edit/delete`, `orders.approve/dispatch`, `finance.post/approve`, `reports.export` and `deliveries.*.execute` already have distinct canonical ability codes. Their complete per-page Dashboard/HTTP/SoD, module-readiness and interaction matrix is **not certified by this focused pricing fix**. Before closing #2818, finish source-wide page/action matrix, cross-store tests, AR/EN/RTL/LTR/a11y UI audit and exact-head required CI for the complete scope. Do not mark this Issue completed based solely on this PR.

## App impact review

- **Customer:** No changed Customer API or pricing calculation; this modification affects `/api/v1/admin/b2b/prices`, not Customer `/api/v1/b2b/products` or checkout. No Flutter source/contract changes required.
- **Driver:** No assignment/status/collection routes or app payloads changed; B2C DRIVER-only fulfillment remains enforced.
- **Van:** No Van app pricing or route/visit/offline API changed; B2B VAN-only fulfillment remains enforced.

No new localized message, visual component or UI route was introduced; the existing AR/EN pricing-page label and RTL/LTR behavior remain authoritative. This is a backend authorization correction; any future changes to mobile-consumed pricing endpoints require separate linked V2 impact Issues.

## Delivery and restrictions

- Branch: `test/2818-v2-page-action-authority-matrix`
- One PR target: `Foodex_V2`
- No `main`, production, release, tags, deployments or UAT claims.
- Add exact final PR HEAD, CI run URLs, outcomes, and merge SHA to the Issue handoff when verified.
