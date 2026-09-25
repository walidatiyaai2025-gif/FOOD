# PH-05 Dashboard Demo / Visual QA Dataset

This dataset exists only to reproduce the approved FOODEX premium dashboard with stable, realistic-looking data. It is **not** production bootstrap data and is never invoked by `DatabaseSeeder`.

## Run

From `backend/` on a non-production environment:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=Database\\Seeders\\DashboardDemoSeeder
```

Then sign in with the demo management account created by the seed process:

- Email: `admin.demo@foodex.test`
- Password: `Demo123!`

Never enable this account or run this seeder on Production. The seeder aborts if Laravel reports the `production` environment.

## Deterministic visual targets

The selected current Kuwait day contains:
- 532 B2C orders.
- KWD 48,532 recognized order value (cancelled/refunded excluded).
- 1,892 sold units.
- Order distribution: 149 processing, 223 out for delivery, 138 delivered, 22 cancelled.
- Recent-order examples aligned with the approved design: #1245 through #1241.
- Low-stock quantities of 5, 8, 10 and 12.
- Customer and Driver mobile production-metadata records for the dashboard app-promotion card.
- Three unread demo notifications.
- 1,248 active demo users once PH-05 presence tracking (`last_seen_at`) is present.

Six prior days are also populated so the 7-day orders/revenue chart is visibly non-empty.

## Cross-surface consistency

All orders, products, customers, inventory, payments and driver assignments use the same authoritative tables consumed by reports, B2C APIs, Customer App and Driver App. No dashboard-only mock JSON is used.

The product images in `backend/public/demo/products` are local FOODEX-branded visual-QA SVG assets. They are generic demo artwork, not production catalog photography.
