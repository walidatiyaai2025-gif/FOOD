# Platform Customer Commerce Execution Plan

Status: **Authoritative worker execution plan**
Approved: 2026-09-29
Parent: #406
Architecture authority: `docs/architecture/PLATFORM_CUSTOMER_COMMERCE.md`

This plan is designed so one worker can execute it sequentially or several workers can execute dependency-unblocked tasks in parallel without silently overlapping business ownership.

---

## 1. Program goal

Complete one coherent FOODEX journey:

```text
One Customer login
   |
   +--> Retail A registration origin
   |      +--> Retail A B2C profile
   |      +--> automatic Wholesale B2B profile/account
   |
   +--> Retail A cart/order/invoice
   +--> Wholesale cart/tier-priced order/invoice
   +--> Retail B lazy B2C profile/order/invoice
   |
   +--> Unified My Orders / My Invoices
   |
   +--> Dashboard Customer 360
   +--> Dashboard multi-line order + invoice
   |
   +--> Driver assigned order + invoice + status/note
          |
          +--> scoped live Dashboard notifications
```

Store/channel ownership remains authoritative throughout.

---

## 2. Worker protocol

Every implementation worker must:

1. Refresh `main`, Issues, PRs and required CI before claiming work.
2. Work only on its exact issue and exact branch.
3. One Issue = One Owner = One Branch.
4. Read:
   - `docs/architecture/PLATFORM_CUSTOMER_COMMERCE.md`
   - `docs/architecture/MULTI_TENANT_ARCHITECTURE.md`
   - `docs/architecture/ROLE_ARCHITECTURE.md`
   - `docs/workflows/ORDER_LIFECYCLE.md`
   - `docs/features/NOTIFICATIONS_CENTER.md`
5. Reuse current domain services before creating new abstractions.
6. Never trust client price/store/customer ownership fields without server validation.
7. Never direct-push to `main`.
8. PR -> required CI -> functional tests -> migration/tenant checks -> squash merge -> branch cleanup.
9. If a dependency is not merged, stop rather than copying its implementation.
10. If a shared file is owned by another active task, coordinate or wait; do not create parallel conflicting implementations.

---

## 3. Atomic queue

| ID | Issue | Exact branch | Dependencies | Primary output |
|---|---:|---|---|---|
| PCX-01 | #407 | `feat/407-platform-customer-origin` | #406 | Platform identity/origin + automatic B2B/B2C materialization |
| PCX-02 | #408 | `feat/408-single-login-store-context` | #407 | One mobile login + store context + unified order history |
| PCX-03 | #409 | `feat/409-platform-customer-pricing-quotes` | #407 | One authoritative quote/reprice engine |
| PCX-04 | #410 | `feat/410-order-invoice-domain` | #409 | InvoiceService + customer/dashboard invoice documents |
| PCX-05 | #411 | `feat/411-dashboard-customer-360` | #407, #410 | Registration-origin Customer 360 + invoices |
| PCX-06 | #412 | `feat/412-dashboard-multiline-order-invoice` | #409, #410 | Dashboard multi-product order + invoice |
| PCX-07 | #413 | `feat/413-driver-invoice-status-notifications` | #410, #412 | Driver invoice/status/note + live Dashboard events |
| PCX-08 | #414 | `test/414-platform-customer-commerce-e2e` | #407-#413 | Full release-blocking acceptance |

---

## 4. Dependency graph and parallelism

```text
#406 Plan
   |
 #407 PCX-01 Identity / Origin
   |----------------------|
   v                      v
 #408 PCX-02           #409 PCX-03
 Mobile/session        Pricing/quote
                          |
                          v
                       #410 PCX-04
                       Invoice domain
                       /          \
                      v            v
                 #411 PCX-05   #412 PCX-06
                 Customer 360  Dashboard order
                                  |
                                  v
                               #413 PCX-07
                               Driver/events
                      \           /
                       \         /
                         #414 PCX-08
                         E2E gate
```

Safe parallel work:
- After #407 merges, #408 and #409 may run in parallel.
- After #410 merges, #411 and #412 may run in parallel.

Do not run #410 before #409, and do not run #413 before #410/#412.

---

## 5. Shared-file collision map

### #407 owns
Likely shared files:
- PlatformCustomer model/service.
- CustomerDomainResolver identity/materialization sections.
- identity/schema migrations.
- registration endpoint/service.
- store default Wholesale tier schema/config.

Downstream workers must consume its contract, not reimplement it.

### #408 owns
- Customer Flutter authentication/session/router/store context.
- unified customer order aggregation endpoint/presentation.
- mobile logout/session behavior.

Avoid pricing/invoice domain changes.

### #409 owns
- B2bPriceResolver integration.
- shared quote/reprice service.
- cart/checkout/admin quote calculations.
- price snapshot fields/contracts.

If `CheckoutController` or `AdminOrderManagementService` must be touched, keep changes limited to quote integration and make the resulting service callable by #410/#412.

### #410 owns
- invoice migrations/model/service.
- invoice endpoints/document rendering.
- checkout -> invoice issuance.
- payment invoice linkage.
- invoice revision/void rules.

### #411 owns
- Dashboard Customer 360 controllers/read models/views.
- origin filters/badges.
- authorized customer order/invoice summaries.

No business calculation changes.

### #412 owns
- Dashboard multi-line order-builder UI.
- AdminOrderManagementService integration with #409/#410.
- order/invoice creation UI and document actions.

### #413 owns
- Driver assignment payload and Driver Flutter invoice view.
- allowed transition/note UX.
- order timeline note visibility.
- notification event publication/deep links.

Reuse Notifications Center/live infrastructure; do not create another notification subsystem.

### #414 owns
- tests/evidence only except minimal test-fixture or clearly isolated repair changes.
- If a real defect is found outside test scope, create a focused repair issue rather than hiding substantial implementation inside the gate.

---

## 6. Data contract target

### Platform customer

Minimum final semantics:

```text
platform_customers
- id
- user_id UNIQUE
- legacy_customer_id compatibility link where retained
- name
- phone
- email
- origin_channel
- origin_store_id NULLABLE
- registration_source
- registered_at
- is_active
- created_at / updated_at
```

### Retail default Wholesale tier

Each Retail store may point to an approved default Wholesale price tier for customers originating there.

Fallback: `STANDARD`.

The client must never choose this tier directly during signup.

### Orders

Orders remain exact store/channel documents.

Required commercial snapshot coverage must be sufficient to reproduce:
- item unit prices;
- quantities;
- B2B pack/min/increment context where applicable;
- discounts;
- delivery;
- tax where modeled;
- grand total;
- currency;
- price tier context where applicable.

### Invoices

Required final ownership/provenance:
- order_id;
- customer compatibility/domain mapping;
- store_id;
- channel;
- Platform Customer link or resolvable provenance;
- invoice number/status;
- totals/currency;
- issue/revision/void provenance;
- B2B price tier/account snapshot where applicable.

Exact migration shape is owned by #410, but these invariants are mandatory.

---

## 7. API behavior matrix

| Actor/surface | Capability | Store requirement | Auth |
|---|---|---:|---|
| Guest Customer App | Browse main Wholesale | Main B2B | No |
| Guest Customer App | Browse Retail | Exact B2C | No |
| Platform Customer | Cart | Exact active store | Yes |
| Platform Customer | Quote | Exact cart store | Yes |
| Platform Customer | Checkout | Exact cart store | Yes |
| Platform Customer | Unified My Orders | Customer aggregate | Yes |
| Platform Customer | Unified My Invoices | Customer aggregate | Yes |
| B2C Store Admin | Retail customers/orders/invoices | Assigned Retail store only | Yes + permission |
| B2B Admin | Wholesale customers/orders/invoices | Wholesale only | Yes + permission |
| SUPER_ADMIN | Customer 360 | Explicit platform scope | Yes + permission |
| Dashboard order creator | Multi-line quote/create | Authorized store only | Yes + permission |
| Driver | Assignment/invoice/status/note | Assigned order only | Yes + driver role |

OpenAPI is updated per implementation task.

---

## 8. Registration flows

### Retail-origin signup

```text
Guest in Retail A
 -> Register
 -> users
 -> platform_customers(origin=b2c, origin_store_id=A)
 -> b2c_customers(store=A)
 -> b2b_customers
 -> active b2b_accounts
 -> price tier = Retail A default or STANDARD
 -> auth token
 -> remain in Retail A
```

### Wholesale-origin signup

```text
Guest in Wholesale
 -> Register
 -> users
 -> platform_customers(origin=b2b, origin_store_id=Wholesale)
 -> b2b_customers
 -> active b2b_accounts(STANDARD unless policy says otherwise)
 -> auth token
 -> remain in Wholesale
```

### Later Retail B usage

```text
Authenticated Platform Customer
 -> open Retail B
 -> browse
 -> first authenticated commerce action
 -> materialize b2c_customer(user, Retail B)
 -> keep same login
```

---

## 9. Pricing and invoice flow

```text
Selected store/cart
 -> server QuoteService
 -> validate product ownership/availability
 -> resolve channel pricing
 -> apply allowed promotions/coupon/delivery/tax
 -> return quote
 -> user/admin confirms
 -> reprice transactionally
 -> create Order + OrderItems snapshots
 -> InvoiceService issues Invoice + InvoiceItems
 -> link Payment.invoice_id
 -> emit order/invoice events
 -> return order + invoice references
```

Client and Dashboard never calculate the authoritative final amount themselves.

---

## 10. Dashboard order-builder acceptance

The Dashboard form is not considered complete unless one operator can:

1. choose authorized customer;
2. choose/add product row 1;
3. choose/add product row 2+;
4. change quantities;
5. remove a row;
6. see backend-recalculated quote;
7. see Wholesale tier constraints or Retail pricing correctly;
8. choose address/payment;
9. add note;
10. confirm once;
11. receive one order with N order items;
12. receive one invoice with matching N invoice items;
13. view/print/download invoice;
14. find the order/invoice in the correct store workspace.

---

## 11. Invoice lifecycle rule

For this program:

- Customer checkout auto-issues an invoice from the final server snapshot.
- Dashboard confirmed order creation issues the invoice from the same domain service.
- An issued invoice is immutable.
- If commercial correction is required, use explicit void/reissue/revision behavior.
- Do not silently mutate invoice rows after issuance.
- Order operational status may continue changing independently of immutable commercial values.

#410 defines the exact status enum/DB fields and migration.

---

## 12. Driver + notification event matrix

| Driver/order event | Persist history/note | Dashboard live event | Invoice available |
|---|---:|---:|---:|
| Order created | Yes | Yes | Yes after issuance |
| Driver assigned/reassigned | Yes | Yes | Yes |
| Driver accepts | Yes | Yes | Yes |
| Picked up | Yes | Yes | Yes |
| Out for delivery | Yes | Yes | Yes |
| Driver adds note | Yes | Yes | Yes |
| Delivered | Yes | Yes | Yes |
| Failed | Yes | Yes | Yes |
| Invoice void/reissue | Invoice audit | Yes | Updated authorized document |
| Relevant payment change | Payment audit | Yes | Payment state visible |

All events are store/channel scoped.

---

## 13. Dashboard Customer 360 minimum UX

Customer list:
- Name.
- phone/email.
- Registered from badge.
- registered date.
- Wholesale tier/status.
- total orders/value visible in the current authorization scope.
- active status.

Customer detail tabs:
1. Overview.
2. Registration / provenance.
3. Wholesale account.
4. Retail store relationships.
5. Orders.
6. Invoices.
7. Addresses.
8. Audit/activity where authorized.

Retail store admins never receive cross-store tabs/data they are not authorized to see.

---

## 14. Unified Customer App account UX

One Account area:
- profile;
- addresses;
- My Orders;
- My Invoices;
- logout.

My Orders / My Invoices:
- grouped or filterable by store;
- store logo/name;
- Wholesale/Retail badge;
- status;
- amount;
- date.

The account does not ask the customer to choose a separate B2B/B2C login identity.

---

## 15. Tests required by task

### #407
Identity/origin/materialization/migration/tier fallback.

### #408
Single login, store switching, separate carts, unified own history, tamper denial.

### #409
Retail-vs-Retail pricing, Wholesale tier pricing, reprice-before-create, snapshot immutability.

### #410
Invoice transactionality, totals, PDF/RTL/LTR, payment linkage, authorization, void/reissue.

### #411
Origin badge/filter, Customer 360 authorization and tenant denial.

### #412
2+ product Retail and Wholesale Dashboard orders, quote correctness, invoice equality.

### #413
Driver-only invoice visibility, status transitions, notes, event scoping, reconnect duplicate suppression.

### #414
Full golden journey and production-like upgrade.

---

## 16. Release/version policy

This program changes persistent schema and mobile behavior.

The first release containing the complete program must:
- bump Dashboard version;
- bump Customer APK version to the same product version;
- bump Driver APK version to the same product version;
- publish matching APK release assets;
- publish a Dashboard update package;
- mark `contains_migrations=true`;
- include migration/upgrade evidence from the currently supported production version.

Do not publish a partial “complete Platform Customer journey” release before #414 passes.

---

## 17. Stop conditions

A worker stops and reports blocked when:
- required dependency is not merged;
- active owner holds the same issue/branch;
- requested schema would violate tenant/customer separation;
- accounting/invoice correction behavior would silently mutate issued commercial values;
- a client-side pricing shortcut would replace backend authority;
- a proposed notification payload leaks cross-store information.

Normal compile/test/CI defects are fixed on the same task branch.

---

## 18. Definition of program done

The program is done only when #414 proves all of the following together:

- one customer login;
- immutable registration origin;
- automatic Wholesale eligibility for Retail-origin customer;
- per-store Retail domain materialization;
- exact store/channel order routing;
- backend-authoritative pricing;
- multi-line Customer and Dashboard orders;
- real customer invoices;
- Customer 360 with origin + invoices;
- Driver invoice/status/note journey;
- scoped live Dashboard notifications;
- unified Customer App order/invoice history;
- migration safety;
- tenant isolation;
- AR/EN acceptance;
- synchronized release artifacts.
