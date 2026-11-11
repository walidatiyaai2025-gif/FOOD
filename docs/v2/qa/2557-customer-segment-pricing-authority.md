# V2 #2557 / P227 — شرائح العملاء / Customer segments in pricing

Source: FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), P227, parent #2503. Integration target **Foodex_V2 ONLY**. Manual browser/device/staging UAT **DEFERRED_TO_V2_FINAL** under the latest owner decision.

## Canonical product placement

| Decision | Authority |
|---|---|
| Business domains | Customer Accounts own segment membership; Catalog & Pricing consumes authorized segments for commercial targeting |
| Entity | commercial_customer_groups, commercial_customer_group_members; product_commercial_rules.customer_group_id for exact B2B products |
| Actor / purpose | Authorized B2B administrator creates a wholesale customer group and targets a permitted B2B product using that group |
| Existing user journey | Accounts → Wholesale Customer Groups → add/update member; Catalog → Commercial & Pricing Control → select exact product → group targeting rule |
| Canonical routes | admin.b2b.customer-groups.index; admin.commercial.sales-control; admin.commercial.sales-control.save |
| Reuse/placement | REUSE existing SIDEBAR nodes customer_groups and commercial_sales_control and current item-level rule editor; no duplicate P227 page or new navigation |
| Read and write authority | customers.view versus customers.manage; catalog.view versus catalog.manage. OperationalTenantScope owns B2B store/channel permission and exact product. Deny overrides win over role; Sidebar gives no grant |
| Scope/readiness | Active, same-store B2B customer segments (and explicitly supported global groups) only; foreign Retail and inactive group IDs rejected by server-side rule validation. No extra module flag or invented entitlement |
| Desktop/mobile web and AR/EN | Existing Admin Shell, responsive tables/forms, keyboard-operable native links, Tajawal, AR RTL and EN LTR. No new markup, styling, inputs or copy |
| Back path | Existing group list after group mutation, selected-product Commercial Control after rule save |

## Real cross-workflow evidence

- Authorized B2B_ADMIN creates a segment via authenticated HTTP. It persists to commercial_customer_groups.
- A legitimate wholesale B2B customer is associated with the segment via the existing members route. The membership is counted on the real group management page.
- The authorized selected-product Commercial & Pricing Control page returns that segment in its actual active, store-scoped ruleGroups lookup; both locales render the group name without technical IDs.
- Existing saveSalesControl persists a rule referencing the exact group and product; a subsequent server read shows its association.
- Deactivated and foreign Retail groups are excluded from selectable groups and rejected by existing HTTP validation, with no unauthorized persisted rule.
- Independent customers.manage DENY preserves read-only group access; catalog.view DENY hides and blocks commercial targeting. Roleless, disabled and Retail B2C actors cannot open the wholesale group/pricing routes by forging direct URLs.

The runtime relationship already exists. This leaf adds **five focused Laravel HTTP regression tests and QA traceability**, not another renderer or redundant basic navigation tests. Existing group management tests prove individual CRUD; P227 verifies the missing cross-domain journey.

## Application impact matrix

**Dashboard:** Cross-workflow tests cover existing Accounts/Commercial pages, read/write permission split, AR/EN, RTL/LTR and store scope; no UI runtime changes.

**Backend:** No route/controller/schema/permission changes. Existing customer group and commercial rule logic remain authoritative.

**Customer Flutter:** Not affected; no changed pricing API response, customer group eligibility logic, checkout, customer notifications or Flutter source. Existing customer pricing remains server-authoritative.

**Driver Flutter:** Not affected; no B2C Driver lifecycle, assignment, GPS, notifications or proof changes.

**Van Flutter:** Not affected; no Van B2B execution, offers, visits or dispatch changes. B2B = VAN ONLY, B2C = DRIVER ONLY preserved.

## Acceptance gates

One Issue #2557, branch test/2557-v2-pricing-customer-segment-journey, one PR targeting Foodex_V2. Require exact-head Repository Policy, FOODEX V2 Isolation, Laravel Backend Tests, AR/EN Localization, Backend Fast-Fail PHP/Pint/PHPStan/security/OpenAPI, real MySQL/Redis Fresh Install/Upgrade/Recovery and terminal required-ci-gate success before squash merge. Fix any failure on SAME branch/PR. Record tested head SHA, CI links, verified merge SHA and issue completion. Parent #2503 stays OPEN for deferred owner manual UAT and release certification. Never merge into main, tag, publish releases or deploy production.
