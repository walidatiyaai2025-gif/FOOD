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
