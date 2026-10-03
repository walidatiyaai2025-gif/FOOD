# Customer Commerce V4 Execution Plan

Status: **Authoritative execution map**
Parent umbrella: #764
Architecture baseline:
- `docs/architecture/PLATFORM_CUSTOMER_COMMERCE.md`
- `docs/architecture/MULTI_TENANT_ARCHITECTURE.md`
- `docs/architecture/ROLE_ARCHITECTURE.md`
- `docs/architecture/APP_PREVIEW_ARCHITECTURE.md`

## Mission

Converge one FOODEX Customer App journey in which one platform Customer identity can buy from both Retail and Wholesale without a separate Business Customer login or a mandatory choose-store detour.

The Dashboard is the source of truth for app-visible Retail Store banners/configuration. Retail Store banners rotate every 5 seconds and route directly to the exact configured store. Wholesale remains a commerce/store context, not an authentication identity.

Order routing is derived and validated from authoritative store/cart/product context. Authentication identity never decides whether an order is Retail or Wholesale.

## Canonical journey

```text
Dashboard-managed config
        |
Customer Home
  |------------------------------|
  |                              |
Retail Store banners        Wholesale entry/catalog
(5-second rotation)               |
  |                               |
exact Retail Store                |
  |                               |
  +----------- Product -----------+
                |
              Cart
                |
      Unified Customer auth
      Remember me + biometric
          when enabled
                |
        Resume exact action
                |
             Checkout
                |
   Backend-authoritative store/channel
          /                  \
         v                    v
 Retail Store order      Wholesale order
 correct Dashboard       correct Dashboard
 correct driver pool     correct driver pool
```

## Non-negotiable owner decisions

1. One Customer login/account across FOODEX.
2. No separate customer-facing Business Customer login.
3. No mandatory Choose Store screen in the normal journey.
4. Retail banner area contains Retail Store banners only.
5. Retail banners auto-rotate every 5 seconds.
6. Banner tap opens the exact configured Retail Store directly.
7. Internal B2B/B2C records remain valid domain/tenant constructs, but are not separate login identities.
8. Auth interruption preserves and resumes the exact commerce action once.
9. Remember me uses the existing session model; biometric login is opt-in and securely stored using platform secure storage.
10. Cart/order ownership is exact store/channel; one user may hold multiple isolated carts.
11. Dashboard and Customer App consume the same published config/contracts.
12. App Preview must use the same Customer runtime; auth persona and commerce context are separate concepts.
13. Existing self-store purchase, tenant, driver and notification isolation remain mandatory.
14. Preserve Customer UI V3 visual language.
15. Remove obsolete Business Login / Choose Store / duplicate customer routes after replacement parity is proven.

## Atomic queue

| Lane | Issue | Exact branch | Depends on | Output |
|---|---:|---|---|---|
| A | #765 | `feat/765-identity-commerce-context-kernel` | none | Unified customer session + commerce context + pending action |
| B | #766 | `feat/766-dashboard-retail-banner-authority` | none | Dashboard-published Retail Store banner authority |
| C | #767 | `feat/767-customer-home-retail-banner-direct-entry` | #765, #766 | 5s Retail carousel + direct exact-store routing |
| D | #768 | `feat/768-unified-auth-remember-biometric-resume` | #765 | Unified auth + Remember me + biometric + exact resume |
| E | #769 | `feat/769-store-scoped-cart-checkout-order-router` | #765 | Store-scoped cart + authoritative checkout/order routing |
| F | #770 | `feat/770-wholesale-customer-e2e-journey` | #765, #768, #769 | Complete Wholesale customer journey |
| G | #771 | `feat/771-retail-customer-e2e-journey` | #765, #766, #767, #768, #769 | Complete Retail customer journey |
| H | #772 | `feat/772-dashboard-order-operational-convergence` | #769 | Correct Dashboard queues/filters/authorization |
| I | #773 | `feat/773-app-preview-customer-parity` | #766, #767, #768, #769, #770, #771 | App Preview parity with unified identity semantics |
| J | #774 | `feat/774-legacy-customer-business-login-store-selector-purge` | #767, #768, #770, #771, #773 | Delete obsolete customer auth/store-selector paths |
| K | #775 | `test/775-retail-wholesale-integrated-e2e-gate` | #770, #771, #772, #773, #774 | Integrated E2E/release evidence gate |

Initial dependency-unblocked lanes: **#765 and #766 only**.

Dependent lanes must not be promoted to `worker:ready` until their dependencies are merged/closed and shared-file ownership is clear.

## Dependency graph

```text
#765 A Identity/Context
 |\
 | \---- #768 D Unified Auth
 | \---- #769 E Cart/Order Router
 |
#766 B Dashboard Retail Banners
  \---- #767 C Home/Retail Entry

#765 + #768 + #769 ---------------- #770 F Wholesale
#765 + #766 + #767 + #768 + #769 -- #771 G Retail
#769 -------------------------------- #772 H Dashboard Ops
#766 + #767 + #768 + #769 + #770 + #771 -- #773 I Preview
#767 + #768 + #770 + #771 + #773 -- #774 J Legacy Purge
#770 + #771 + #772 + #773 + #774 -- #775 K E2E Gate
```

## Ownership fences

### #765
Own customer commerce-context/router kernel and only the backend customer/domain resolution required to validate context.

### #766
Own Dashboard Storefront/Banner editor/publish configuration and Retail placement API payload.

### #767
Own Customer Home/Marketplace Retail banner presentation and direct entry. Do not implement auth/cart logic here.

### #768
Own Customer auth/session persistence, Remember me, biometric adapter and unified auth presentation. Consume #765 pending-action contract.

### #769
Own cart/checkout/order routing and backend validation. Do not redesign customer screens.

### #770 / #771
Own journey-specific presentation/adapters after shared kernel/auth/cart contracts merge. They must not fork shared routing/auth/cart engines.

### #772
Own Dashboard order operational read models/filters/views and authorization presentation.

### #773
Own App Preview session/runtime/dashboard bridge only. Reuse real Customer APIs/runtime.

### #774
Own deletion/cleanup after replacement coverage is green.

### #775
Own integrated tests/evidence. Product defects discovered here go back to the responsible lane rather than being hidden inside test code.

## Mandatory integrated acceptance

### Retail
Guest -> Home -> Retail banner -> exact Retail Store -> browse/search -> product -> cart -> unified auth/register if needed -> exact action resumes -> Retail address/payment -> checkout -> Retail order -> exact Retail Dashboard queue -> authorized Retail driver -> delivered -> customer tracking.

### Wholesale
Same app/account -> Wholesale entry -> catalog -> product -> cart -> same unified auth/session -> Wholesale pricing/MOQ/address/payment -> checkout -> Wholesale order -> Wholesale Dashboard queue -> Wholesale driver -> delivered -> customer tracking.

### Same account
One authenticated Customer can complete a Retail order and a Wholesale order without logout, account switching or a Business Customer login.

### Auth interruption
Signed-out add-to-cart or checkout in either context resumes exactly once after auth/register with the same store/product/cart context. No stale B2B route may produce a misleading "data unavailable" state.

### Dashboard
Retail banners are controlled by published Dashboard configuration. Runtime banner rotation is 5 seconds. Orders appear only in the authoritative operational scope.

### Preview
Published Dashboard Customer App Preview renders the same Home/banner/store/Wholesale behavior as the standalone Customer runtime. Store/channel scope remains commerce context, not customer identity.

### Negative gates
- no cross-store cart/order leakage;
- no Wholesale/Retail queue leakage;
- no client-forged customer-type/store/channel routing;
- no self-store purchase regression;
- no unauthorized deep-link/auth-return bypass;
- no reachable customer-facing Business Login or mandatory Choose Store path after #774.

## Worker / handoff protocol

All workers follow `AGENTS.md`.

Mission command:

```text
FOOD #764 AUTO-HANDOFF
```

This means:
- reconstruct state from GitHub;
- start/resume only dependency-unblocked non-conflicting children;
- one Issue = one owner = one branch = one PR;
- reuse existing branch/PR after interruption;
- checkpoint repository-visible state at least every 10 minutes while executing;
- own CI to green/merge or a genuine external blocker;
- promote the next dependency-unblocked child to `worker:ready`;
- continue until #764 is complete or human-gated.

## Completion rule

#764 closes only when #765-#775 are complete and #775 proves Retail + Wholesale journeys end-to-end against integrated `main`, including Dashboard operational routing and App Preview parity.

---

# Corrective Closure Wave — Approved 1.0.47 APK -> Full 1.0.48 Release

Status: **Authoritative corrective closure map**
Parent umbrella: #764
Owner-approved Customer runtime baseline: `5d902c2c2fd632c27b67e3bed49473dec55975f0`
Existing baseline Issue/PR: #794 / #796
Current published release identity: `1.0.47`
Next immutable release target: **1.0.48**

## Why this wave exists

Owner acceptance testing of the 1.0.47 Customer APK exposed a remaining identity convergence defect for a Retail Store owner who is also the store's linked Wholesale purchasing customer.

The runtime baseline itself is retained. The repair must converge identity, own-store purchase exclusion, order-history presentation and Dashboard owner binding without replacing the approved home/navigation behavior.

The following decisions are authoritative:

1. A Retail Store owner/primary manager uses one existing `users` login for Dashboard and Customer App.
2. The same User owns/manages the Retail store and resolves the store-linked Wholesale purchasing account.
3. No separate Business Customer login, Wholesale password or account switch exists.
4. A Retail merchant may buy Wholesale and may buy from other Retail stores.
5. A Retail merchant must never buy from a Retail store they own/manage.
6. The merchant's own Retail store must disappear from authenticated Customer marketplace discovery; backend enforcement remains mandatory even if a stale/deep link is used.
7. My Orders is split into two explicit tabs: Wholesale Orders and Retail Orders.
8. Existing store/channel provenance is immutable; changing active store after login never reassigns an existing order.
9. Dashboard provisioning/editing must maintain the same primary-owner User -> Retail management -> linked Wholesale purchasing identity.
10. All changes ship once as a complete **1.0.48** release after integrated CI/E2E is green.

## Baseline preservation lane — #794 / PR #796

The exact owner-tested APK runtime came from:

`5d902c2c2fd632c27b67e3bed49473dec55975f0`

PR #796 currently contains that runtime plus later regression-test commits only. Workers must continue the **same branch and PR**; no replacement baseline branch/PR is allowed.

Required closure:
- preserve the approved Wholesale hero -> Retail carousel -> Wholesale content order;
- preserve 5-second Retail rotation and exact-store routing;
- repair the four current Customer Flutter regressions in-place;
- required CI green;
- merge PR #796 to `main`;
- then promote #797 and #799 to actionable work.

## Corrective atomic lanes

| Lane | Issue | Branch (stable target) | Depends on | Primary output |
|---|---:|---|---|---|
| Baseline | #794 | existing `fix/794-customer-retail-after-wholesale-apk` | none | Approved APK runtime + regression tests merged to main |
| L | #797 | `fix/797-retail-merchant-canonical-identity` | #794 | Canonical merchant User, linked B2B account reconciliation, migration/backfill |
| M | #798 | `fix/798-retail-merchant-own-store-exclusion` | #797 | Own-store hidden/rejected across marketplace, route, cart, quote and checkout |
| N | #799 | `feat/799-customer-orders-channel-tabs` | #794 | Wholesale/Retail My Orders tabs using existing channel filter |
| O | #800 | `fix/800-dashboard-retail-owner-binding` | #797 | Dashboard primary-owner binding/reassignment/visibility |
| K | #775 | existing `test/775-retail-wholesale-integrated-e2e-gate` | #794, #797-#800 | Integrated regression/E2E gate |
| P | #801 | `release/801-foodex-1.0.48` | #775 | Final main-integrated APK + Dashboard Update + immutable release registry |

## Dependency graph

```text
#794 / PR #796 approved APK baseline
   |----------------------|
   v                      v
#797 Identity          #799 Orders tabs
   |                     |
   |                     |
   v   v                  |
#798 Own-store       #800 Dashboard owner
                      /
                     /
     +-----> #775 <---+
              |
              v
          #801 Release 1.0.48
```

### Safe parallelism

After #794 merges:
- #797 and #799 may run in parallel because their primary ownership fences are disjoint.
- #798 waits for #797's canonical identity contract.
- #800 waits for #797 and consumes its service rather than duplicating identity logic.
- #775 may scaffold fixture/test orchestration but cannot close until #794 and #797-#800 are merged.
- #801 is release-only and must not start promotion until #775 closes.

## Lane L — #797 canonical Retail merchant identity

### Required data contract

The owner/primary manager `User` is the authentication principal.

For a linked Retail store:
- `retail_wholesale_accounts.owner_user_id = users.id`;
- the linked `b2b_customer` resolves to that same User;
- the existing `b2b_account` and price tier are preserved;
- historical orders/invoices/replenishment provenance remain on the same linked business customer/account.

Backfill must be:
- non-destructive;
- idempotent;
- production-like MySQL safe;
- conflict-detecting rather than silently re-parenting ambiguous identities.

Successful Customer App login/profile must expose the authoritative Retail merchant payload already defined by the API contract.

## Lane M — #798 own-store exclusion

Authenticated merchant owning Store A:

Allowed:
- principal Wholesale commerce using the linked Store A purchasing context;
- Retail Store B/C discovery and purchase.

Forbidden:
- Store A Retail banner/list/search presence;
- Store A storefront through stale/deep link;
- Store A Retail cart/add/quote/checkout;
- forged Store A request headers/IDs.

Backend denial is the security boundary. UI filtering is a usability mirror, not the authority.

Guest discovery before login may still show Store A. After authentication, marketplace state must refresh and remove it.

## Lane N — #799 My Orders tabs

The Customer Orders UI becomes two tabs:

1. **طلبات الجملة / Wholesale Orders**
   - uses `GET /api/v1/orders?channel=b2b`;
2. **طلبات التجزئة / Retail Orders**
   - uses `GET /api/v1/orders?channel=b2c`.

Each tab owns independent loading/error/empty/refresh/pagination state.

Order cards/details retain:
- order ID/number;
- original store;
- original channel;
- status;
- total/currency;
- store logo/name where available.

Opening a historical order must use its original `store_id + channel`, never current marketplace selection.

## Lane O — #800 Dashboard owner binding

Retail Store provisioning/edit must explicitly maintain:
- Primary Owner/Manager User;
- `B2C_STORE_ADMIN` assignment;
- `retail_wholesale_accounts.owner_user_id`;
- linked Wholesale purchasing account/tier.

The Dashboard shows a read-only merchant linkage summary:
- owner name/email;
- Customer App identity active/linked;
- Wholesale purchasing account linked;
- Wholesale tier.

Changing primary owner:
- is atomic;
- is audited;
- removes stale merchant entitlement from the old owner;
- grants entitlement to the new owner;
- does not rewrite historical order ownership.

No separate Wholesale password field is introduced.

## Final gate — #775

#775 is release-blocking and must prove at minimum:

- owner-tested #794 runtime behavior survives integration;
- one normal Platform Customer can buy Retail + Wholesale;
- one Retail merchant can login with the same User credentials;
- the merchant resolves the exact existing linked Wholesale account/tier;
- no duplicate B2B account/customer is created;
- own Retail Store is hidden and backend-rejected;
- other Retail stores remain purchasable;
- Wholesale remains purchasable;
- My Orders tabs are channel-isolated;
- switching store never mutates historical order provenance;
- Dashboard owner creation/reassignment keeps identity/account links coherent;
- migration/backfill is idempotent on production-like MySQL;
- Preview parity, operational routing, invoices, drivers and notifications remain isolated;
- Arabic RTL and English LTR pass;
- all required CI is green on integrated `main`.

## Release lane — #801 / 1.0.48

Release identity rules:
- `1.0.47` is already Published and immutable.
- The corrective payload must therefore ship as `1.0.48`.
- No artifact built from an intermediate child branch is a promoted release.

#801 must produce from final merged `main`:
- synchronized `VERSION=1.0.48`;
- Customer Android release APK;
- Dashboard `FOODEX-Update` package including migration/backfill;
- App Preview runtime from the same final source/contracts;
- CHANGELOG + `UPDATE_NOTES_1.0.48.md`;
- one append-only 1.0.48 release-registry entry;
- recorded final main SHA + APK SHA256 + update-package SHA256;
- required CI/release validation green.

## Handoff mission command

The repository-owner command remains:

```text
FOOD #764 AUTO-HANDOFF
```

For this corrective closure wave it means:

1. finish #794/PR #796 in-place and merge it;
2. promote and drain #797 + #799 in parallel when safe;
3. drain #798 and #800 after #797;
4. run/close #775 against integrated main;
5. execute #801 and produce the complete 1.0.48 artifacts;
6. update #764 checkpoints after every material queue transition;
7. stop only when #764 completion is satisfied or a genuine external gate remains.

No worker should ask the owner to reconstruct this chat. GitHub Issue/PR state plus this document are authoritative.

