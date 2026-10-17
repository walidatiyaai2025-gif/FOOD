# Catalog, Product & Commercial Completion Contract (#1978)

## Placement decision

**Surface:** Dashboard Web  
**Domain:** Catalog / Product / Commercial  
**Actor:** authorized catalog operator / administrator  
**Canonical parent:** existing Catalog Management workspace  
**Canonical route:** `/admin/catalog`  
**Placement:** **Reuse → Extend → Compose**  
**Primary patterns:** Module Overview + list-management + exact-record 360 detail inside the existing workspace.

No new PLM module, product master, store-product ledger, pricing authority, inventory ledger, sales-control authority, sidebar domain or report engine is introduced.

Navigation remains the existing Catalog/Product entry. The workspace adds sibling tabs for Overview and Attributes while preserving Products, Categories and Import/Export. Product 360 and Category 360 are contextual exact-record views reached from their canonical grids.

## Authoritative data

- Product identity: `products`.
- Store/channel catalog ownership: `catalogs`.
- Store price/cost/assignment state: `store_products`.
- Category / brand / unit: existing catalog + lookup tables.
- Inventory truth: `inventories` scoped through active `warehouses`.
- Commercial state: existing `product_commercial_policies`, `product_selling_units`, `product_availability_windows`, `product_commercial_rules`.
- Sales truth: existing `orders` / `order_items`.
- Audit truth: existing `audit_logs`.
- Enterprise reports/export/schedules: existing Enterprise BI report registry, queue and scheduler.

#1978 adds only lifecycle/attribute/import-run governance required to complete the existing product surface.

## Dashboard definitions

All KPIs are calculated server-side for the actor's authorized store scope. Query-string scope and date controls never expand authorization.

- **Active SKUs:** product lifecycle `active` and product active.
- **Inactive SKUs:** total authorized SKUs minus active SKUs.
- **Out of stock:** authoritative available inventory quantity is zero or lower.
- **Low stock:** positive available inventory is at or below the product low-stock threshold.
- **Unpriced:** no store assignment price exists.
- **Unavailable:** lifecycle inactive, product inactive, assignment inactive or an existing commercial policy is closed.
- **Category coverage:** percentage of authorized products with category assignment.
- **Brand coverage:** percentage of authorized products with brand assignment.
- **Margin exception:** canonical store price/cost is missing or non-positive according to the corresponding report/exception definition.
- **Recent changes:** persisted import runs plus audited lifecycle transitions created in the last seven days.

Charts reuse the shared FOODEX visualization vocabulary and provide text/table fallback:
- product mix by category;
- product mix by brand;
- availability distribution;
- price/margin distribution;
- sales by product;
- sales by category;
- import-error trend.

Every actionable KPI/exception links back into the existing Catalog grid with exact filter parameters.

## Typed product attributes

Attribute definitions are owned by one catalog and may be global to that catalog or assigned to one category in the same catalog.

Supported types:
- text;
- number;
- yes/no;
- date;
- single select;
- multi-select.

Definitions contain independent Arabic and English labels. Select types require an allowed-value set. Required/type/allowed-value constraints are enforced server-side before values are persisted. Values stay attached to the existing product identity rather than creating another product record.

Attribute definition/value changes are audited.

## Product lifecycle

Lifecycle vocabulary:
`draft → active ↔ paused → end_of_life`.

The service records:
- source state;
- target state;
- effective date;
- actor;
- reason.

The existing `products.is_active` flag remains compatible and is synchronized from lifecycle state. Existing product edit active-state changes create lifecycle history rather than silently diverging from the new lifecycle field. A product referenced by historical orders is moved to end of life rather than physically deleted.

## Product 360

Product 360 is an exact-record composition in the existing Catalog workspace. It exposes only authorized product data:

- identity, SKU and owning store/channel;
- category, brand and unit;
- images;
- typed attributes;
- lifecycle and transition history;
- store price/cost;
- current stock summary;
- existing commercial policy state;
- selling-unit count;
- availability-window count;
- targeting/quota rule count;
- recent orders;
- audit history.

For B2B products, commercial management drills into the existing Sales Control route; Catalog does not edit a second commercial-policy model.

## Category 360

Category 360 provides:
- owning store/catalog context;
- product count;
- active product count;
- unpriced product count;
- child categories;
- category/global attribute definitions;
- top products from canonical order lines;
- audit history.

## Bulk import / export

The existing ZIP parser remains authoritative. #1978 wraps it with a persistent import-run ledger:

- package SHA-256;
- store/catalog/channel scope;
- validation counts;
- row-level errors and warnings;
- `validated / validation_failed / committing / committed / failed` state;
- committed result;
- committed timestamp.

A valid confirmation token is claimed atomically before commit. Repeating a successfully committed confirmation returns the persisted result and does not replay mutations. In-flight duplicate confirmation fails closed.

Arabic sessions receive localized row/file diagnostics while preserving row numbers, stable codes and file paths required to repair the upload.

The Catalog bulk CSV export reads the same product/store/commercial/stock authorities and does not create a new product ledger.

## Reports and scheduled reporting

The existing Enterprise BI registry owns these reports:

- `catalog.availability`;
- `catalog.category_brand_performance`;
- `catalog.margin_exceptions`;
- `catalog.lifecycle`;
- `catalog.data_quality`.

They use `CatalogOperationsReadModel` and inherit:
- report entitlements;
- store-scope authorization;
- queued/chunked exports;
- idempotent export requests;
- saved/scheduled report infrastructure.

No second report queue or scheduler is introduced.

## Responsive / localization / accessibility

The page retains the existing Dashboard shell and adds shared Module Overview primitives from #1968.

Acceptance matrix:
`320 / 360 / 390 / 412 / 430 / 768 / 1024 / 1280+`, Arabic RTL and English LTR.

Requirements:
- no page-level horizontal overflow;
- tables remain bounded/paginated;
- bounded 360 histories declare their explicit bounds;
- KPI/chart meaning is available as text;
- mutation controls are duplicate-submit locked;
- status/lifecycle/availability labels are localized before display;
- business users are not asked to type internal database IDs or raw JSON;
- exact-record 360 actions remain keyboard reachable.

## Migration / rollback

The migration is additive:
- lifecycle columns use backward-compatible defaults;
- existing inactive products backfill to paused;
- existing product/category/store-product authorities are not replaced;
- typed-attribute tables reference canonical product/catalog/category records;
- import-run history is append-only operational provenance.

Down migration removes only #1978 additions.

## Completion gate

#1978 is complete only when the exact PR head passes repository policy, formatter/static/backend tests, AR/EN localization, database deployment/migration acceptance and Dashboard runtime visual evidence; then the canonical PR merges to `main`, the Issue closes, and the branch is cleaned up.
