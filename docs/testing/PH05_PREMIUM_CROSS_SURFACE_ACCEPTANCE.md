# PH-05 Premium Cross-Surface Acceptance

Issue #116 is the release-facing QA gate for the FOODEX premium visual system introduced by #113, #114 and #115. The gate validates the seeded dashboard contract, Arabic/English directionality, responsive layout source contract, shared brand tokens and representative Customer/Driver journeys without moving any business rule into the clients.

## Deterministic setup

From `backend/` on a non-production environment:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=Database\\Seeders\\DashboardDemoSeeder
```

The demo account is documented in `docs/design-reference/PH05_DEMO_DATA.md`. The seeder is prohibited in Production.

## Automated acceptance

Backend:

```bash
cd backend
php artisan test --filter=PremiumDashboardAcceptanceTest
php artisan test --testsuite=Feature
```

Customer:

```bash
cd apps/customer_app
flutter test
```

Driver:

```bash
cd apps/driver_app
flutter test
```

The required CI gate must also complete successfully for the PR. The #116 branch deliberately touches Backend, Customer and Driver test areas so all applicable validation lanes execute.

## Dashboard reconciliation evidence

`PremiumDashboardAcceptanceTest` seeds the same database fixture used for visual QA and asserts the dashboard service returns:

- 532 orders for the selected Kuwait day.
- KWD 48,532 recognized value.
- 1,892 sold units.
- Distribution: 149 processing, 223 out for delivery, 138 delivered, 22 cancelled.
- Low-stock values beginning 5, 8, 10 and 12.
- Recent seeded order `FOODEX-DEMO-1245`.

The rendered dashboard is also asserted to contain the canonical FOODEX green/orange tokens, RTL in Arabic, LTR in English, and the three responsive breakpoint contracts implemented for desktop/tablet/mobile collapse.

## Visual viewport checklist

Capture the seeded dashboard with browser zoom at 100% and no browser-side CSS overrides:

| Viewport | Expected contract |
| --- | --- |
| 1536×1152 | Right RTL sidebar, 4 KPI cards, 2/3 chart + 1/3 distribution, lower 3-card row. |
| 1180 px width | KPI 2×2, middle stack, lower cards reflow without clipping. |
| 860 px width | Sidebar collapses from the canvas, header/search reflows, single application column. |
| 620 px width | Single-column KPI/cards, compact recent orders, touch-oriented quick actions. |

For English, repeat the desktop smoke with `locale=en`; the sidebar/directional layout must mirror to LTR while preserving the same FOODEX tokens and information hierarchy.

## Cross-surface evidence

Customer test coverage verifies:
- Arabic-first RTL shell and explicit English LTR mirror.
- Guest B2C product browsing and cart access.
- Authenticated B2C checkout states.
- B2B approved-account boundary, pricing/finance screens, loading/empty/error states.
- Shared FOODEX theme/status colors.

Driver test coverage verifies:
- Arabic-first RTL shell and explicit English LTR mirror.
- Backend-derived B2C/B2B driver role partition.
- Real canonical assignment/status HTTP paths.
- Loading/empty/error/offline/session-expiry behavior.
- B2C/B2B channel isolation.
- Shared FOODEX theme/status colors.

Backend feature coverage retains permission denial and store isolation for B2C administration. #131 is a prerequisite of this acceptance and supplies the production Driver runtime wiring; isolated pre-#131 widget coverage is not accepted as Driver E2E evidence.

## Pass rule

#116 passes only when:

1. Seeded dashboard figures reconcile with authoritative tables/service queries.
2. Arabic RTL and English LTR smoke pass on Admin, Customer and Driver.
3. Responsive source contract and canonical FOODEX tokens are present.
4. Existing permission/store/channel isolation tests remain green.
5. Customer and Driver key-flow tests remain green.
6. Repository Policy and `required-ci-gate` are green.
7. No P0/P1 visual or functional regression is open from this QA pass.

This gate does not certify store signing, published mobile artifacts or production credentials; those remain owned by #125.
