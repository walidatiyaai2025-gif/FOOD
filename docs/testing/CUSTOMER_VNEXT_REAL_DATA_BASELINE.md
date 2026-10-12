# Customer VNext real-data intake

Issue: #1330  
Parent: #1323

The owner-supplied MariaDB dump is private source material. It must never be committed to this public repository.

## Private-source preflight

Before deriving any sanitized fixture, verify that the private dump is the exact Customer VNext baseline:

```bash
python3 scripts/customer-vnext-real-data-preflight.py /private/path/solscool_foodex.sql \
  --output /tmp/foodex-customer-vnext-source-manifest.json
```

The command exits non-zero when the relational counts do not match the #1330 baseline. The JSON output contains only the source SHA-256, byte size, documented/observed counts, mismatch metadata, and names of sensitive tables that were detected. It never exports source rows or values.

Expected source shape:

| Table | Rows |
|---|---:|
| products | 39 |
| product_images | 39 |
| product_selling_units | 36 |
| categories | 17 |
| stores | 3 |
| store_products | 39 |
| orders | 82 |
| order_items | 150 |
| payments | 93 |
| translations | 566 |

A dump that does not match this shape is stale or otherwise not the approved 2026-10-12 intake and must not be used to generate the repository fixture.

## Sanitization boundary

The repository fixture may preserve business-safe relational shape, statuses, pricing structure, category/store/product relationships and deterministic timestamps needed for tests. It must replace personal identities with fixed QA identities and must never contain production contact details, passwords, password hashes copied from production, auth/session tokens, push/device tokens, payment provider references, provider credentials, audit request metadata, or other secrets.

Raw SQL stays outside the repository. Only deterministic sanitized seed assets and non-sensitive source-manifest metadata may be committed.

## Official catalog boundary

The official FOODEX launch catalog is a separate authoritative seed layer. Product names, package sizes, carton counts and prices must come from the owner-approved product document; do not fabricate missing values. Embedded thumbnails are reference images only. The launch image pipeline must reject missing or low-resolution required assets rather than stretching thumbnails.

## Test

```bash
python3 -m unittest scripts.tests.test_customer_vnext_real_data_preflight
```


## Repository-owned sanitized operational fixture

The canonical test/staging fixture is now:

- `database/seeders/FoodexCustomerVNextFixtureSeeder.php`
- `database/seeders/fixtures/foodex-customer-vnext-baseline.json`

The JSON fixture contains business-safe source lineage, product/category/store semantics, prices and aggregate lifecycle distributions only. It contains no production people, addresses, phones, emails, passwords, tokens, provider references or raw private rows. Fixed tester identities use `@example.test`, explicit QA addresses and synthetic phone values.

The fixture reproduces the verified relational shape exactly inside its own `FDX-QA-*` namespace: 3 stores, 17 categories, 39 products/images/store-products, 36 selling units, 82 orders, 150 order items, 93 payments, 566 AR/EN translations, 10 QA users and 5 QA customers. Order channel/status and payment provider/status distributions match the approved Oct-12 dump.

Seed only the sanitized operational baseline:

```bash
cd backend
php artisan db:seed --class=FoodexCustomerVNextFixtureSeeder
```

Seed the Customer VNext launch contract (official 25-product catalog plus the sanitized operational baseline outside production):

```bash
cd backend
php artisan db:seed --class=FoodexLaunchSeeder
```

`FoodexCustomerVNextFixtureSeeder` refuses to run in production. `FoodexLaunchSeeder` never installs the QA operational fixture in production and delegates launch readiness to the official catalog image/source checks, so production remains fail-closed until unresolved owner values and approved >=800 px product/banner assets are supplied.

The operational fixture deliberately uses the repository's generic demo image for QA browsing. It is not production image evidence and cannot satisfy the official catalog image-quality gate.
