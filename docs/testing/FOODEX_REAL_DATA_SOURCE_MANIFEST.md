# FOODEX Real Data Source Manifest — 2026-10-12

Parent: #1323
Implementation: #1330

## Purpose
Record the exact owner-supplied source inputs used to build FOODEX tester/launch seed data without publishing sensitive production data in this public repository.

## Current database snapshot
Source filename: `solscool_foodex (3).sql`
Database: `solscool_foodex`
Dump generation: 2026-10-12 13:06 UTC (source header)
MariaDB source version: 10.11.18
Source size: 2,548,349 bytes
SHA-256: `dd8ad844f963ffe0317a4ba4346d61fae7ce0d02909b3d05394d9497ea2b9023`

Schema intake found approximately 128 tables.

Representative row counts from the supplied dump:
- products: 39
- product_images: 39
- product_selling_units: 36
- categories: 17
- stores: 3
- store_products: 39
- users: 10
- customers: 5
- orders: 82
- order_items: 150
- payments: 93
- translations: 566

The verified Oct-12 snapshot has **566** translation rows. The earlier **230** figure came from an older intake snapshot and is superseded; it must not cause rejection of this fingerprint. Other table counts must be recomputed from the private source rather than inferred from older snapshots. Provenance: the verified source checkpoint recorded in [#1330](https://github.com/walidatiyaai2025-gif/FOOD/issues/1330).

### Privacy/security rule
Do not commit the raw SQL dump to GitHub. Intake inspection confirmed real addresses/contact details and authentication/token-bearing tables. The public repository receives only a sanitized deterministic derivative.

The sanitizer/fixture implemented by #1330 must eliminate or replace:
- real names, emails, phones and addresses
- passwords/password hashes not intentionally created for test users
- personal access tokens
- push device tokens
- provider credentials/secrets
- reset tokens or other authentication material
- any PII embedded in JSON/audit/notification payloads

Cross-table referential integrity and realistic business lifecycle examples must be preserved.

## Official FOODEX product source
Source filename: `منتجات فوديكس كاردات (1).docx`
Approximate source size: 903 KB
SHA-256: `1333292c13e6bbddacc26f728485021459f914964af29ec589894086241cc523`
Document length: 8 pages
Listed products in the source summary: 25
Embedded media files: 25

The document includes official product naming plus package size, carton quantity, price where supplied and product imagery. Examples include:
- فراولة فرست تشويس — 1 كجم — 10 عبوات — 575
- مانجا فرست تشويس — 1 كجم — 10 عبوات — 750
- ملوخية فرست تشويس — 400 جم — 20 عبوة — 200
- بامية زيرو فرست تشويس — 400 جم — 20 عبوة — 700
- بامية ممتاز فرست تشويس — 400 جم — 20 عبوة — 700
- multiple First Choice / FOODEX fries variants
- natural/analogue/premium mozzarella variants
- ketchup, mayonnaise, mustard, barbecue, harissa, hot sauce, pizza sauce
- strips / pane / shish tawook entries

Source prices are transcribed values; intake does not resolve whether a particular price applies to a pack/carton or authorize a conversion. In particular, missing sauce/poultry prices, missing carton counts and the alternative strips carton/price values remain unresolved until source review. The seed/readiness gate must represent these gaps explicitly.

Product values must be transcribed from the source document only; missing/ambiguous values are not invented and must be flagged for owner/data review.

## Image intake
The ordinary embedded JPEG/PNG product assets inspected from the DOCX are roughly 311–422 px on their long side. They can be used as identity/mapping references but are not accepted as final large storefront assets.

#1330 must:
1. map each source image to the correct product;
2. prefer approved original high-resolution images when available;
3. otherwise create and review an approved high-resolution derivative;
4. enforce image minimum-quality checks before launch;
5. prevent thumbnail stretching in Product Details or cards;
6. verify responsive crop/fit on the approved 3-column mobile product grid.

## Tester provisioning contract
The final repository-owned fixture must give any engineer/tester:
- deterministic setup command
- deterministic reset/reseed command
- known test users/roles documented without secrets
- realistic Customer products/categories/stores/orders/payments/inventory states
- official FOODEX launch catalog seed
- complete local/test image assets or deterministic asset loading
- no dependency on private production data after fixture generation

The Dashboard protected reset/reseed surface is tracked by #1331; production preflight/Launch is #1332.
