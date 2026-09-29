# FOODEX Unified Platform Customer Commerce Architecture

Status: **Authoritative — approved 2026-09-29**
Owner: FOODEX Coordinator
Parent plan issue: #406
Execution plan: `docs/execution/PLATFORM_CUSTOMER_COMMERCE_EXECUTION_PLAN.md`

This document defines the end-to-end customer, store, pricing, order, invoice, driver and notification model for FOODEX.

It supersedes any older wording that implies a Customer App user must maintain separate Wholesale and Retail logins, or that a retail-origin consumer must manually register again as a Wholesale customer.

---

## 1. Business outcome

FOODEX has one Customer App and one customer login.

A person may register while browsing:
- the main Wholesale store; or
- any Retail store.

That registration creates one **Platform Customer** identity. The customer can subsequently shop from the main Wholesale store and from any active Retail store using the same login.

The purchase itself always belongs to the store and commerce channel in which it was placed:

- Wholesale purchase -> main Wholesale store -> `channel=b2b`.
- Retail A purchase -> Retail A -> `channel=b2c`, `store_id=Retail A`.
- Retail B purchase -> Retail B -> `channel=b2c`, `store_id=Retail B`.

A shared login is an identity convenience. It must never weaken store ownership, tenant isolation, pricing ownership, order ownership, invoice ownership or Dashboard authorization.

---

## 2. Current baseline workers must reuse

The current codebase already contains foundations that must be extended rather than duplicated:

- `platform_customers` and `App\Models\PlatformCustomer`.
- `App\Services\PlatformCustomerService`.
- `App\Services\CustomerDomainResolver`.
- separate `b2b_customers` and store-scoped `b2c_customers`.
- `b2b_accounts`, price tiers and `App\Domain\Pricing\B2bPriceResolver`.
- store/channel-owned carts and orders.
- `AdminOrderManagementService` with array-based multi-line order support.
- `invoices`, `invoice_items` and `payments.invoice_id` schema foundations.
- Driver assignments, backend-authoritative delivery transitions and persisted driver/status notes.
- Dashboard live notification infrastructure.

Workers must not introduce a second customer identity system, a second pricing engine, a second order model, a second invoice table family or a second notification center.

---

## 3. Canonical identity model

### 3.1 Authentication identity

`users` remains the authentication identity.

For Customer App commerce, one user has:
- one email/login identity;
- one password/auth token family;
- one `platform_customers` row when the user is a Platform Customer.

No store-specific login is created for the same customer.

### 3.2 Platform Customer

`platform_customers` is the cross-store customer identity bridge.

Required provenance fields:

- `origin_channel`: `b2b` or `b2c`.
- `origin_store_id`: nullable FK to the exact store where registration happened.
- `registration_source`: e.g. `customer_app`, `dashboard`, `migration`, `import`.
- `registered_at`.
- active/status fields already required by the platform customer lifecycle.

Registration origin is historical provenance and is **immutable** after creation. Shopping later in another store does not change it.

For migrated customers whose exact source cannot be proven, use a deterministic migration value such as `registration_source=migration` and nullable origin store rather than inventing a source.

### 3.3 Business-domain customer records

Platform identity does not replace business-domain ownership.

- Wholesale operations use `b2b_customers` + `b2b_accounts`.
- Retail operations use `b2c_customers` scoped by exact `store_id`.

A Platform Customer may therefore map to:
- exactly one active Wholesale B2B customer/account; and
- zero, one or many Retail B2C customer rows, one per Retail store actually used.

### 3.4 Automatic Wholesale eligibility

Every active Platform Customer is eligible to purchase from the main Wholesale store.

At registration, FOODEX must ensure:
- one `b2b_customer`;
- one active `b2b_account`;
- one valid Wholesale price tier.

Tier resolution:
1. If registration originates from a Retail store and that store has an approved default Wholesale customer tier, use it.
2. Otherwise use the platform `STANDARD` Wholesale tier.
3. Never infer a tier from client input.
4. A later tier change affects future quotes only, never historical order/invoice snapshots.

This automatic consumer Wholesale profile is distinct from a manually managed corporate/credit B2B account workflow. Existing approval rules for special credit/business accounts remain valid.

### 3.5 Retail materialization

If the customer registers in Retail A:
- create/materialize Retail A `b2c_customer` immediately;
- create Wholesale profile/account immediately;
- do not create arbitrary Retail B/C rows.

When the same customer later performs authenticated commerce in Retail B:
- materialize exactly one Retail B `b2c_customer`;
- do not copy Retail A tenant data;
- preserve the same Platform Customer identity.

---

## 4. Dashboard registration-origin visibility

The Dashboard must make customer provenance explicit.

Minimum Customer 360 fields:
- Platform Customer ID.
- name/email/phone.
- active state.
- **Registered from** badge:
  - `Wholesale — <store name>`, or
  - `Retail — <store name>`.
- registration source and date.
- current Wholesale account status and price tier.
- Retail stores in which a scoped B2C profile exists.
- order count/value by store/channel.
- recent orders.
- recent invoices.

Authorization:
- SUPER_ADMIN may see the full cross-platform Customer 360 view.
- B2B_ADMIN sees Wholesale-relevant customer/account/orders/invoices.
- B2C_STORE_ADMIN sees only its own store's Retail customer/orders/invoices. It must not gain another Retail store's rows through the Platform Customer bridge.
- Cross-store provenance exposure beyond the safe registration badge requires an explicit platform contract; raw tenant data is never exposed.

Dashboard filters must include origin channel/store/source/date and active state.

---

## 5. Customer App journey

### 5.1 Entry

After splash the Customer App opens the main Wholesale marketplace without login.

Guests may:
- browse Wholesale;
- browse Retail banners;
- open Retail storefronts;
- browse products and offers.

Authentication is required for customer-owned operations such as authenticated carts/checkout, addresses, favorites, order history and invoices according to the active contract.

### 5.2 Retail banners

Retail stores are presented above the main Wholesale content as a compact horizontal banner rail around 20% of usable viewport height.

Selecting a banner changes active store context to that exact Retail store.

### 5.3 One login

Platform Customers never re-authenticate merely because they move between Wholesale and Retail.

The mobile session carries one token. Each commerce request carries/resolves:
- active store;
- channel;
- authenticated Platform Customer.

Legacy users that are not Platform Customers retain their existing authorization boundaries until explicitly migrated.

### 5.4 Separate carts

A cart is owned by one exact store/channel.

The app may keep multiple store carts, but must never merge products from different stores into one order.

Switching store:
- preserves the other store's cart;
- shows the selected store's cart;
- never silently moves items across stores.

---

## 6. Pricing authority

Pricing is backend authoritative.

Clients and Dashboard forms may display calculated prices, but may not submit trusted unit prices.

One quote/reprice path must be shared by:
- Customer cart review;
- Customer checkout;
- Dashboard order creation.

### 6.1 Retail pricing

Retail pricing is resolved from the exact Retail store:
- active store product price;
- store promotions;
- valid store/channel coupon;
- delivery fee policy;
- tax policy when configured.

Retail A pricing must not be reused for Retail B.

### 6.2 Wholesale pricing

Wholesale pricing is resolved from:
- active B2B account;
- current price tier;
- `B2bPriceResolver`;
- MOQ;
- ordering increment;
- pack/case rules;
- account credit policy where applicable.

### 6.3 Quote and snapshot

Before final confirmation the backend returns an authoritative quote containing at minimum:
- store/channel;
- customer/account context;
- item lines;
- quantity;
- unit price;
- line total;
- discounts;
- delivery;
- tax where applicable;
- grand total;
- currency;
- price-tier code/id snapshot for Wholesale;
- quote timestamp/identifier if introduced.

At order creation FOODEX persists sufficient immutable snapshots to reconstruct the commercial decision. Later catalog, price or tier changes must not change an existing order or invoice.

---

## 7. Order ownership and routing

Every order stores authoritative:
- `store_id`;
- `channel`;
- correct domain customer FK;
- Platform Customer provenance through the mapped identity;
- price snapshots;
- order status.

Routing is simple and deterministic:
- Retail order -> selected Retail store Dashboard.
- Wholesale order -> Wholesale Dashboard.

SUPER_ADMIN may inspect cross-platform orders only through explicit authorized platform views.

A Retail admin never sees another Retail store's order, even when both orders belong to the same Platform Customer.

---

## 8. Dashboard multi-line order creation

Authorized Dashboard users must be able to create a complete order containing multiple products.

The order builder must support:
- Customer selection within authorized scope.
- Exact store/channel context.
- 1..N product rows.
- add/remove row.
- quantity per row.
- Warehouse selection where required by Wholesale.
- address.
- payment method.
- note.
- authorized coupon/discount inputs only.
- backend quote/reprice.
- visible subtotal/discount/delivery/tax/grand total.
- final confirmation.

Product selectors must be scoped to the active store/catalog/channel.

Wholesale rows must enforce tier/MOQ/increment/pack rules.

The existing array-based `AdminOrderManagementService` is the foundation and must be completed, not replaced by single-line order code.

All mutations are audited.

---

## 9. Invoice domain

An invoice is a first-class financial/commercial document, not merely an order screen.

### 9.1 Invoice creation

Customer App checkout:
- order + order lines + invoice + invoice lines + payment link are created through one controlled business flow;
- if the transaction fails, no partial invoice/order state may remain.

Dashboard order creation:
- after authoritative quote and confirmation, create the multi-line order and issue its invoice through the same InvoiceService.

### 9.2 Invoice content

Invoice header includes:
- invoice number;
- order number;
- store identity;
- channel;
- customer identity;
- registration/business context only where appropriate;
- issue date;
- currency;
- subtotal;
- discount;
- delivery;
- tax when configured;
- grand total;
- payment method/status;
- Wholesale price tier/account snapshot when applicable.

Invoice lines include:
- product/SKU description snapshot;
- quantity;
- unit price;
- line discount/tax if modeled;
- line total.

Invoice values come from order/quote snapshots, never from live current catalog prices.

### 9.3 Payment linkage

`payments.invoice_id` must link the payment to the issued invoice.

### 9.4 Immutability and correction

An issued invoice's commercial values are immutable.

No Dashboard edit may silently change an already-issued invoice.

Correction requires an explicit audited workflow such as:
- void original invoice;
- create replacement/revision invoice;
- link revision provenance.

Workers must not solve this by mutating the original invoice in place.

### 9.5 Invoice access

Customer App:
- unified My Invoices list for the authenticated Platform Customer;
- store/channel badge;
- detail;
- print/download/share-safe document endpoint.

Dashboard:
- authorized list/detail;
- customer invoice section in Customer 360;
- order -> invoice link;
- Print/View/Download.

Documents must render correctly in Arabic RTL and English LTR.

---

## 10. Driver contract

A driver receives only orders assigned to that driver and matching the driver's channel/store authorization.

The Driver App assignment detail must include a **safe invoice/order financial summary**:
- invoice number;
- order number;
- store;
- delivery customer/address;
- items;
- quantities;
- line totals;
- grand total;
- payment method/status.

Do not expose unrelated B2B account credit, cross-store history or platform-wide finance.

### 10.1 Driver actions

The backend remains authoritative for allowed transitions.

Driver may perform only transitions returned/allowed by the backend, e.g.:
- accept;
- picked up;
- out for delivery;
- delivered;
- failed;
- retry transition when valid.

Each transition may carry an optional note.

Every note/change records:
- driver/user actor;
- timestamp;
- from/to status;
- note;
- order/store/channel.

The Dashboard order timeline shows these notes and transitions.

---

## 11. Live Dashboard notification contract

Operational changes must appear as scoped live Dashboard notifications.

Minimum events:
- order created;
- invoice issued;
- invoice reissued/revised;
- invoice voided;
- payment status changed where operationally relevant;
- driver assigned;
- driver reassigned;
- driver accepted;
- picked up;
- out for delivery;
- delivered;
- failed;
- driver note added.

Each event carries sufficient context for an authorized deep link:
- store_id;
- channel;
- order_id;
- invoice_id when applicable;
- driver/assignment id when applicable;
- event type;
- timestamp.

Audience rules:
- Retail A event -> Retail A authorized Dashboard users only.
- Retail B event -> Retail B authorized Dashboard users only.
- Wholesale event -> Wholesale authorized Dashboard users.
- SUPER_ADMIN receives platform-level events only according to explicit permission/configuration.
- No event payload may leak another tenant's customer/order/invoice data.

Reconnect/retry must be idempotent: no duplicate unread event for the same event identity.

Arabic and English notification copy must be supported.

---

## 12. Unified customer history

The Customer App should present one account-level history while preserving store provenance.

### My Orders
Aggregate the caller's authorized Platform Customer orders across:
- Wholesale;
- Retail A;
- Retail B;
- any later Retail store.

Each item displays store name/logo and channel badge.

### My Invoices
Same aggregation model with invoice/store/channel badge.

The aggregation is customer-authorized, not an unscoped cross-tenant admin query.

Dashboard users continue to see only their authorized store/domain slices.

---

## 13. Address ownership

Target behavior: the customer should not have to create duplicate personal addresses merely because they shop at another store.

A Platform Customer address-book layer may be introduced or the existing address model may be safely reconciled so that:
- the customer owns the address;
- checkout validates it for the selected domain customer/order;
- stores cannot enumerate unrelated customers' addresses;
- historical orders retain the address snapshot/reference required for audit.

Implementation must be migration-safe and must not break current order history.

This requirement belongs to the Platform Customer journey and must be included in E2E acceptance even if implemented as a compatibility bridge first.

---

## 14. API contract direction

Exact endpoint names may reuse current routes where compatible, but the resulting contract must expose these capabilities:

Public/guest:
- marketplace/main Wholesale browse;
- Retail store browse.

Authenticated Platform Customer:
- register/login/logout/me;
- store-scoped cart;
- authoritative quote;
- checkout;
- unified orders;
- unified invoices;
- addresses/profile/favorites.

Dashboard:
- Customer 360;
- scoped multi-line order quote/create;
- invoice list/detail/document;
- driver/order timeline.

Driver:
- assignments;
- assigned order/invoice summary;
- allowed transitions with note.

OpenAPI must be updated with implemented final paths/schemas.

---

## 15. Transactionality and idempotency

Customer checkout must remain idempotent.

At minimum:
- Idempotency-Key identifies one checkout intent.
- replay with identical payload returns the same order/invoice;
- changed payload under the same key is rejected;
- order and invoice issuance are transactionally consistent;
- notification publication is retry-safe and does not duplicate logical events.

Dashboard order creation should use equivalent duplicate-submit protection where practical.

---

## 16. Security and tenant invariants

Non-negotiable:
1. Same customer login does not grant Dashboard/store-admin permissions.
2. Retail A admin cannot read Retail B customers/orders/invoices.
3. Retail A order cannot contain Retail B products.
4. Wholesale order cannot consume Retail inventory/prices.
5. Retail order cannot use Wholesale price-tier rules.
6. Invoice follows order store/channel ownership.
7. Driver follows assignment store/channel ownership.
8. Client-submitted price is never authoritative.
9. Registration origin is immutable provenance.
10. Historical commercial snapshots are immutable after invoice issuance.
11. Cross-store entity-ID tampering returns 403/404 without existence leakage.
12. SUPER_ADMIN cross-store access is explicit and auditable.

---

## 17. Migration policy

This program requires versioned, non-destructive migrations.

Expected schema work may include:
- Platform Customer registration-origin fields.
- Retail-store default Wholesale price-tier reference.
- invoice ownership/provenance/revision fields where missing.
- order/invoice pricing snapshot fields where missing.
- address compatibility/platform ownership fields if required.
- indexes/unique constraints supporting one identity + per-store domain mapping.

Rules:
- no database reset;
- backfill before making constraints strict;
- preserve old IDs/references;
- migration must be safe for the current MySQL/MariaDB production profile;
- Update Center package must set `contains_migrations=true`.

---

## 18. Observability and audit

Audit at minimum:
- Platform Customer registration and origin.
- tier assignment/change.
- Dashboard-created order.
- invoice issue/void/reissue.
- driver assignment/reassignment.
- driver status/note.
- manual payment status mutation.
- SUPER_ADMIN support access.

System Inspector/logging must record actionable exceptions for failed pricing, checkout, invoice generation and notification delivery without leaking secrets.

---

## 19. End-to-end acceptance scenario

The release-blocking golden scenario is:

1. A new customer opens Retail A and registers once.
2. Dashboard shows **Registered from: Retail A**.
3. Retail A B2C profile exists.
4. An active Wholesale B2B profile/account exists automatically with the correct default tier.
5. Customer buys multiple items from Retail A.
6. The order appears only in Retail A operations.
7. A Retail A invoice is issued and visible to customer and authorized Dashboard users.
8. Same login opens Wholesale.
9. Wholesale products show the customer's assigned tier pricing.
10. Customer buys multiple Wholesale products.
11. Order appears only in Wholesale operations and produces a Wholesale invoice with tier snapshot.
12. Same login enters Retail B and purchases there.
13. Retail B B2C profile is materialized, and Retail B order/invoice remain isolated from Retail A.
14. Customer's unified My Orders/My Invoices shows all three purchases with correct store/channel badges.
15. Dashboard creates a separate multi-product order for an authorized customer, quotes server-side and issues an invoice.
16. Assigned driver sees only the assigned order/invoice summary, changes allowed status and writes a note.
17. The correct Dashboard receives live scoped notifications for the driver/order/invoice events.
18. Historical prices/invoices remain unchanged after product/tier price changes.
19. Cross-store tampering tests fail safely.
20. Arabic RTL and English LTR flows pass.

No release may claim this program complete until the scenario passes against the real backend and production-like MySQL environment.
