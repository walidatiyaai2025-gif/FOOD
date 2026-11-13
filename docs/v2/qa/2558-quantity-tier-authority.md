# V2 #2558 — Quantity tiers / شرائح الكميات (P228)

## Authority and placement

- Source: V2 Sidebar IA legacy matrix P228 (actual source document paragraph P217); parent #2503.
- Domain: Catalog & Pricing; entity: canonical B2B price rule; actor: wholesale pricing manager.
- User goal: configure minimum order quantity and ordering increment for a selected active Wholesale price tier and product without exposing internal identifiers.
- Workflow: repeatable administrative configuration; low-density inline form and bounded rule grid.
- Existing parent: `/admin/b2b/pricing-approvals` (`admin.b2b.pricing-approvals`), accessible from the Wholesale dashboard and authorized Sales Control price-list context.
- Placement: **Reuse/Extend** existing pricing management workspace. No new route, Sidebar node, table or pricing engine; volume thresholds are not implemented as a separate progressive-slab pricing model.
- Navigation: existing Wholesale dashboard link `data-v2-pricing-lists-hub` and Sales Control `pricingListsUrl`, only when effective `b2b.pricing.view` authorization and pricing mode permit.
- Permissions: `b2b.pricing.view` for read, `b2b.pricing.manage` for writes. Dashboard reads and writes remain pinned to canonical Wholesale store; authenticated management API writes preserve pre-existing authorized multi-store B2B operation and use `OperationalTenantScope` to reject unauthorized store scope, foreign channel, inactive store, and explicit store DENY. Explicit 404 concealment for denied scope; 403 for missing permission; 409 when pricing tiers are disabled.
- Desktop/tablet/mobile web: reuse existing compact FOODEX workspace form/table, labelled inputs and existing responsive wrappers; Arabic RTL and English LTR via paired locale keys.
- States: tiers-disabled denied, user unauthorized denied, per-store override DENY enforced, retail-product rejection, editable existing price rule, historical audit event unchanged.

## Security/behavioral acceptance scope

1. Management API `GET /api/v1/admin/b2b/prices` and `PUT /api/v1/admin/b2b/prices` both enforce actual store-scope authorization, not only a global role grant.
2. A manager can submit only a B2B store authorized by inherited roles and user overrides; an explicit store-specific DENY overrides a global B2B role. Existing legitimate cross-B2B-store admin API use remains compatible.
3. The existing canonical B2B price resolver and cart quantity rules retain their prior behavior; no mobile pricing shape or version changes are introduced.
4. The existing form exposes numeric `minimum_quantity` and `ordering_increment` with AR/EN accessible labels.
5. Focused `V2QuantityTierAuthorityTest` covers allowed creation, persistence, user-specific foreign-store DENY, B2C-product rejection, disabled mode and Arabic/English Dashboard authority.

## Application impact matrix

| Surface | Impact | Basis |
| --- | --- | --- |
| Backend/Admin pricing API | **Affected** | Authenticated management GET uses canonical-store scope; PUT uses requested B2B store and effective role/override authorization |
| Dashboard | **Affected** | Existing authorized pricing workspace now exposes minimum quantity and increment |
| Customer | Not affected by contract delta | `/api/v1/b2b/products`, `/api/v1/b2b/products/{product}` and cart quote/checkout response semantics unchanged; existing minimum/increment behavior preserved |
| Driver | Not affected | B2C dispatch/status/collection APIs and app code unchanged |
| Van | Not affected by contract delta | Van pricing quote and B2B visit/order/dispatch flows unchanged; no public Van API schema changed |

No Customer/Driver/Van runtime contract or source was changed, so no duplicate mobile implementation Issue is introduced for this bounded authority fix.

## Evidence requirements and residual source acceptance

- Exact-head Laravel, Pint/static-analysis, repository policy, localization, V2 isolation, Required CI and relevant UI policy gates must pass on the PR HEAD before merging to **Foodex_V2 only**.
- Do not close the full P228 Issue unless the source owner confirms that the existing per-product minimum/increment rule is the intended **quantity tiers** concept. The existing backend has one price rule per product/customer-price-tier, not multiple graduated unit-price breaks based on purchased quantity; this slice must not be described as implementing an unverified stepped-volume pricing engine.
- Owner manual UAT is deferred to the final V2 gate under parent #2503, not claimed here.
- No main/release/tag/production actions.
