# V2 #2622 — P292 plural Cashboxes collection acceptance

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, original Sidebar item P292 (**الخزائن**), V2 master #2503.
- Branch: `test/2622-v2-cashboxes-collection-authority`; target: **Foodex_V2 only**.
- Related, already integrated scope: #2623 / PR #3110 (P293 **الصندوق**) covers record-level Cashbox create/update, read-only, per-store DENY and audit. Do not implement it again.

## Business placement and authority

| Dimension | Resolved contract |
| --- | --- |
| Domain / entity | Finance & Treasury / collection of enterprise cashboxes |
| Actor | Authorized B2B finance operator or Retail Finance user with an assigned store |
| Goal | Browse/filter a treasury cashbox collection and choose only authorized locations |
| Canonical route | `admin.treasury.master.index?tab=cashboxes` |
| Navigation | Existing Finance `treasury_master` entry and `Cashboxes` page tab; Retail users can reach Treasury Master from the existing Treasury workbench |
| Pattern | **Reuse** existing Treasury Master page + page-level tab, not a second sidebar/route or a duplicate single-record editor |
| Scope | `finance.view` and `OperationalTenantScope::allowedStoreIds/assertStore`; forbidden requested location is denied server-side |
| Mutations | Existing `finance.manage` POST/PATCH in #2623; none added by this issue |
| Readiness | Cashbox is existing reference/master data; no automatic ERP Treasury transactional enablement or bypass of disabled-module checks |

The existing `TreasuryMasterController::index` uses a server-scoped `whereIn('store_id', allowedStoreIds)`, refuses a foreign `store_id`, filters by status and search, orders the result, and paginates with an explicit allowlist of 25/50/100 rows and a `cashboxes_page` parameter. Search, status and pagination are applied **inside** the scoped query, not before it. `withQueryString()` carries filter state to subsequent pages.

## Added executable regression

`backend/tests/Feature/V2CashboxesCollectionAuthorityTest.php` covers these P292-specific collection requirements without modifying P293 implementation:

1. AR/RTL and EN/LTR render the real cashbox collection. Search plus active/inactive status filtering never leaks a foreign store's record. A direct foreign store query is rejected with 404.
2. A 26-row authorized collection is split at 25, the next-page link exists, and page two contains only the expected record with the store/search/status context retained. A foreign store's cashbox never appears on either page.
3. A Retail Finance actor with two legitimate store assignments sees only those locations in the selector and only those cashboxes in the data table; filtering to one authorized store suppresses the other; an unassigned third store is absent.

**Existing complementary evidence:** #2623 / PR #3110 already proves authorized B2B Sidebar navigation, retail CRUD and audit, read-only mutation denial, explicit DENY precedence and roleless HTTP 403. This issue does not repeat those tests or modify its owned paths.

## UI/UX and accessibility classification

The code change is a feature regression and QA trace only; **no Blade, route, sidebar, JS, CSS, API, model, migration or production service was changed**.

- Layout / desktop / tablet / mobile web: the canonical Treasury Master uses shared FOODEX layout, title, readable labelled filters, page tabs, table wrapper and an existing mobile-web single-column form breakpoint. Existing layout is reused; no untested claim of physical-device or browser UAT.
- Localization: `treasury.cashboxes`, search/status/empty/actions and form labels use AR/EN catalogs. The regression verifies actual response locale direction and translated tab label, without introducing new translation keys.
- Form fields: store is a protected Master Data lookup; status is Enum; search is free text; page size is Enum. A user never supplies a raw internal store key in the visible selector.
- Functional states: data list, filters, empty set, direct unauthorized location, and pagination. Existing Controller and shared components remain responsible for a11y and keyboard navigation.
- Cross-store and role boundaries are enforced by Laravel HTTP checks; UI hiding is not treated as authorization.
- UI placement: Reuse → no new screen, no Sidebar addition, no second navigation authority; existing Finance → Treasury Master → Cashboxes is canonical.

## MOBILE IMPACT — Customer / Driver / Van

- **Customer App — NOT AFFECTED:** no customer API, checkout, credit, pricing or Flutter contract change.
- **Driver App — NOT AFFECTED:** no B2C dispatch, wallet, collection, settlement or mobile source changes.
- **Van App — NOT AFFECTED:** no B2B routes, dispatch, visit/stock/remittance or mobile source changes.

The B2B=VAN ONLY and B2C=DRIVER ONLY fulfillment invariants remain unchanged. This PR changes no runtime files and does not justify rebuilding all mobile apps.

## Acceptance gates and release boundary

Require final PR HEAD `Repository Policy`, `FOODEX V2 Isolation Gate` and terminal `FOODEX Required CI Gate` all **SUCCESS**, including applicable Laravel test, AR/EN, formatting/security and MySQL/Redis installation/upgrade/recovery jobs. Record actual workflow runs and final SHA before merging; no success is presumed here. Manual browser/device UAT is **DEFERRED_TO_V2_FINAL** under master #2503, not described as executed.

No changes to `main`, release/tag, deployment, APK, cPanel, live site or production.
