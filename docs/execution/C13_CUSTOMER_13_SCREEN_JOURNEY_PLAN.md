# C13 — Complete 13-Screen Customer Journey Plan

Status: **Authoritative execution map**  
Umbrella: **#858**  
Mission keyword: **`C13`**  
Governance issue: **#857**  
Baseline rule: **complete the existing Customer App surfaces; do not create a new canonical Customer screen or redesign the app to avoid finishing an existing screen.**

## Mission

C13 completes the customer's visible end-to-end B2B/wholesale journey across exactly **13 canonical Customer screens**.

The mission is not complete because routes or APIs exist. A capability counts only when the customer can see it, understand it, reach it through normal navigation or an obvious action, execute it successfully, and recover from loading/empty/error/stale states.

No required C13 function may be:
- hidden behind an undocumented deep link;
- implemented only in an API with no visible Customer action;
- present only in mock/fixture/preview data while presented as live;
- inaccessible because a card, row, tab, button or navigation entry is missing;
- duplicated on a replacement screen while the existing canonical screen remains incomplete.

Existing child routes such as addresses, settings, notifications and checkout/address-payment remain sub-surfaces owned by one of the 13 canonical screens. They do **not** become Screen 14+.

## Financial semantics

The C13 financial model is shared and authoritative.

- `signed_balance = total_debits - total_credits`.
- `signed_balance > 0`: **customer owes company / عليك**.
- `signed_balance < 0`: **company owes customer / لك**.
- `signed_balance = 0`: settled.
- `credit_limit` is separate from the account balance.
- `outstanding_receivable = max(signed_balance, 0)`.
- `customer_credit_balance = max(-signed_balance, 0)`.
- `available_credit_line = max(credit_limit - outstanding_receivable, 0)`.
- Purchasing power may combine customer credit balance and available credit line only according to the authoritative checkout/payment policy.
- Currency comes from the authoritative account/store context; no C13 screen may hard-code a different finance currency.
- Balance is derived from auditable ledger movements; workers must not create a writable "balance" shortcut that bypasses ledger history.

Minimum ledger movements: opening balance, invoice, payment, credit note, debit note, return, refund, positive adjustment, negative adjustment.

## Atomic queue

| Lane | Issue | Existing Customer surface | Depends on | Stable branch |
|---|---:|---|---|---|
| Foundation | #859 | Dashboard Customer 360 / Finance shared contract | none | `feat/859-c13-financial-foundation` |
| S01 | #860 | `/entry` + unified auth | none | `feat/860-c13-s01-entry-login` |
| S02 | #861 | `/b2b/dashboard` | #859 | `feat/861-c13-s02-dashboard` |
| S03 | #862 | `/b2b/reports/purchases` | #859 | `feat/862-c13-s03-purchases-report` |
| S04 | #863 | `/b2b/products/top` | none | `feat/863-c13-s04-top-products` |
| S05 | #864 | `/b2b/invoices` | #859 | `feat/864-c13-s05-invoices-list` |
| S06 | #865 | `/b2b/account-statement` | #859 | `feat/865-c13-s06-account-statement` |
| S07 | #866 | `/b2b/orders` | none | `feat/866-c13-s07-my-orders` |
| S08 | #867 | `/b2b/orders/:id` | #866 | `feat/867-c13-s08-order-details` |
| S09 | #868 | `/b2b/invoices/:id` | #859, #864 | `feat/868-c13-s09-invoice-details` |
| S10 | #869 | `/b2b/products` | none | `feat/869-c13-s10-products-browse` |
| S11 | #870 | `/b2b/products/:id` | #869 | `feat/870-c13-s11-product-details` |
| S12 | #871 | `/b2b/cart` + existing checkout sub-surface | #859, #869, #870 | `feat/871-c13-s12-cart-checkout` |
| S13 | #872 | `/b2b/profile` + owned existing account sub-surfaces | #859 | `feat/872-c13-s13-profile` |
| Final | #873 | integrated E2E gate | #859-#872 | `test/873-c13-integrated-e2e` |

## Fastest safe parallelism

Recommended initial pool: **5 workers**.

Start only these dependency-unblocked lanes:
- #859 Foundation
- #860 Screen 1
- #863 Screen 4
- #866 Screen 7
- #869 Screen 10

Then:
- after #859: #861, #862, #864, #865, #872 can start;
- after #866: #867 can start;
- after #869: #870 can start;
- after #864 + #859: #868 can start;
- after #859 + #869 + #870: #871 can start;
- #873 starts only after all implementation children are merged.

Workers must obey `AGENTS.md`: One Task = One Owner = One Branch = One PR. Existing branch/PR always wins over creating a replacement.

---

# Shared foundation — #859

This is not a 14th Customer screen. It owns the shared backend/Dashboard contract needed by multiple screens.

Required:
- append-only/auditable account ledger;
- correct signed balance and direction;
- separate credit limit and available credit;
- authoritative currency;
- running balance API/read model;
- Customer 360 visible finance summary;
- visible Dashboard controls inside existing Finance/Customer 360 surfaces for Record Payment, Credit Note, Debit Note, Opening/Adjustment and Refund;
- permission/store/account isolation;
- exact audit provenance;
- filters/export where existing Finance export surfaces support them;
- partial-payment, overpayment/customer-credit, refund, return, adjustment and opening-balance tests.

No screen lane may fork its own balance formula.

---

# Screen 1 — Entry / Login — #860

Existing surface: `/entry` + current unified Customer auth.

Must visibly support:
- obvious Login action;
- unified Customer identity, with no separate hidden Business Customer login;
- visible validation and safe server errors;
- exact intended Customer context preserved after auth;
- B2B-eligible customer routed into the existing correct Customer journey;
- Register/Create Account entry when current journey supports it;
- Remember Me/session restore and biometric opt-in when supported;
- visible language/support entry through the existing shell/actions;
- loading/offline/auth failure/locked/inactive/retry states;
- clean logout return from Screen 13;
- AR/EN, RTL/LTR and accessibility.

Done means no user needs a deep link or undocumented step to enter the journey.

# Screen 2 — Customer Dashboard — #861

Existing surface: `/b2b/dashboard`.

Must visibly show:
- customer/company identity;
- refresh / last-updated state;
- current balance with **عليك / لك**;
- credit limit;
- available credit;
- open invoices;
- overdue amount;
- purchases this month;
- payments this month;
- invoice count;
- order count / active orders;
- existing offers/banner area linked to a real destination;
- obvious tappable shortcuts from cards to owning C13 screens;
- live authoritative data with no fake fallback;
- explicit loading/empty/stale/error states;
- AR/EN, RTL/LTR and account/store isolation.

Dashboard totals must reconcile with Screens 3–6.

# Screen 3 — Purchases Report — #862

Existing surface: `/b2b/reports/purchases`.

Must visibly support:
- period presets and custom From/To;
- total purchases;
- invoice/order count;
- average order value;
- period comparison when data exists;
- trend chart;
- category distribution that reconciles to filtered totals;
- visible drill-through to existing product/order/invoice destinations where applicable;
- exact-result export/download when the current report/export capability supports it;
- correct currency;
- large-range performance/pagination;
- loading/empty/no-results/error/stale;
- AR/EN and RTL/LTR.

Every metric must change consistently when the period changes.

# Screen 4 — Top Purchased Products — #863

Existing surface: `/b2b/products/top`.

Must visibly support:
- period selector aligned with purchases-report semantics;
- ranking by clearly labeled quantity/value mode;
- image, product, pack/variant, purchased quantity, value/spend, last purchase when available;
- product tap to Screen 11;
- visible Buy Again/Add action only after current stock/price/MOQ validation;
- unavailable reason when repurchase is not possible;
- no historical-price reuse as current checkout price;
- functioning visible search/filter/sort when present;
- pagination/loading/empty/error;
- AR/EN, RTL/LTR and isolation.

# Screen 5 — Invoices List — #864

Existing surface: `/b2b/invoices`.

Must visibly support:
- period/date/status/reference filters;
- statuses including paid, open/unpaid, partially paid, overdue and credited/cancelled where applicable;
- invoice number, issue date, due date, total, paid, outstanding, currency, status;
- filtered summary totals for total/paid/outstanding/overdue;
- tap to Screen 9;
- visible PDF/download action;
- pagination and live refresh;
- strict account/store isolation;
- loading/empty/no-results/error/stale;
- AR/EN and RTL/LTR.

Amounts/statuses must reconcile with Screens 6 and 9.

# Screen 6 — Account Statement — #865

Existing surface: `/b2b/account-statement`.

Must visibly support:
- From/To and quick periods;
- opening balance;
- period debit;
- period credit;
- closing/current balance;
- explicit **عليك / لك** wording;
- transaction rows: date, type, reference, description, debit, credit, running balance;
- opening/invoice/payment/credit note/debit note/return/refund/adjustment movements;
- tappable related reference when an existing destination exists;
- exact running-balance reconciliation;
- visible filtered PDF/Excel/download action;
- authoritative currency;
- pagination/large statement behavior;
- loading/empty/error/stale;
- AR/EN and RTL/LTR.

# Screen 7 — My Orders — #866

Existing surface: `/b2b/orders`.

Must visibly support:
- status tabs/counts from stable authoritative status codes;
- active and history states;
- order number/date/store/status/total/currency/item count on cards;
- search/date filter when current controls allow;
- tap to Screen 8;
- delivered/failed/cancelled auditable history without false active-card presence;
- visible Reorder where safe, rebuilding from current products/prices/stock and reporting unavailable lines;
- foreground/live invalidation;
- pagination/loading/empty/error/stale;
- AR/EN, RTL/LTR and isolation.

# Screen 8 — Order Details & Tracking — #867

Existing surface: `/b2b/orders/:id`.

Must visibly show:
- order number/date/store/channel/status/total;
- complete line items with image/product/variant/pack/quantity/unit price/line total;
- subtotal/discount/tax/delivery-fees/grand total;
- delivery address/contact snapshot;
- visible Map action when location data permits;
- payment method/status and account-credit effect;
- authoritative timestamped lifecycle timeline;
- driver/contact/tracking only when authorized and assigned;
- visible related Screen 9 invoice link;
- context-sensitive allowed actions only;
- live foreground update;
- loading/error/stale and terminal failed/cancelled states;
- AR/EN and RTL/LTR.

# Screen 9 — Invoice Details — #868

Existing surface: `/b2b/invoices/:id`.

Must visibly show:
- invoice number/status/issue/due dates;
- seller/customer identity;
- authoritative currency;
- complete invoice lines and discounts/taxes;
- subtotal/discount/tax-fees/total/paid/outstanding-or-credit;
- payment allocation history;
- partial payment, overpayment, credit note, return/refund effects;
- related Screen 8 order link;
- visible PDF/download/share action using the authoritative document;
- no raw storage/internal notes;
- loading/error/stale/permission-safe not-found;
- AR/EN and RTL/LTR.

Displayed arithmetic, PDF, list and ledger must agree.

# Screen 10 — Products Browse — #869

Existing surface: `/b2b/products`.

Must visibly support:
- search;
- category navigation;
- current sort/filter controls;
- product image/name/brand-category/pack-variant/account-tier price/stock/promotion;
- visible out-of-stock state;
- server and client prevention of invalid add;
- visible MOQ/quantity-step/pack constraints;
- obvious Add to Cart feedback;
- tap to Screen 11;
- authoritative customer/account price tier;
- pagination/infinite load;
- loading/empty/no-results/error/stale;
- AR/EN, RTL/LTR and isolation.

# Screen 11 — Product Details — #870

Existing surface: `/b2b/products/:id`.

Must visibly show:
- images/name/SKU/reference where public/brand/category/description/variant-pack-unit;
- current account-tier price and valid promotion;
- stock/MOQ/quantity step/max available;
- validated quantity selector with visible invalid-input reason;
- obvious Add to Cart;
- existing related/alternative component if already present;
- visible handling of price/stock changes between load and add;
- automatic recovery when stock becomes positive;
- loading/error/stale/not-found;
- AR/EN and RTL/LTR.

# Screen 12 — Cart & Checkout — #871

Canonical surface: `/b2b/cart`. The existing checkout/address-payment route is an owned sub-surface of Screen 12, not a new canonical screen.

Must visibly support:
- complete cart lines and availability;
- quantity edit/remove/clear;
- server-authoritative MOQ/step/stock revalidation;
- subtotal/discount/tax/delivery-fees/grand total;
- customer-credit balance if any;
- amount currently owed;
- credit limit;
- available credit line;
- authoritative payment options;
- account-credit eligibility based on outstanding balance + available credit, not raw credit_limit alone;
- obvious address select/add/edit through existing address surface;
- final review with customer/store/lines/totals/address/delivery/payment;
- idempotent submit/retry;
- final stock/price/credit revalidation;
- recoverable failure preserving the cart and exact reason;
- success to Screen 8 and immediate downstream consistency with Screens 2/5/6/7;
- loading/offline/error/expired-session recovery;
- AR/EN, RTL/LTR and store isolation.

# Screen 13 — My Account / Profile — #872

Existing surface: `/b2b/profile`.

Must visibly support:
- customer/company identity and contact;
- account status and permitted business/tax reference;
- account balance with **عليك/لك**;
- credit limit and available credit;
- obvious links to Orders, Invoices, Statement, Purchases Report and existing Notifications;
- Addresses entry with add/edit/delete/default/map behavior through the existing address sub-surface;
- language/notification/account settings through existing settings surface;
- supported credential/security action through existing modal/subroute;
- help/support/contact entry when currently supported;
- visible Logout returning to Screen 1;
- no orphan address/settings/notifications route that cannot be found through Profile or normal navigation;
- loading/empty/error;
- AR/EN, RTL/LTR and privacy/permission tests.

---

## Cross-screen non-negotiable gates

### Visibility / discoverability
Every C13 function must have a visible entry point in its owning screen or normal navigation. The final gate must maintain an automated route/action inventory proving this.

### Authoritative data
No Customer screen may present fixtures, stale snapshots or hard-coded sample values as live. API/network/auth failures must render a real error/stale state.

### Navigation
A customer must be able to traverse the journey without knowing route names. All 13 screens must be reachable through normal shell navigation, Dashboard shortcuts, row/card taps or visible Profile links.

### Finance
One purchase, partial payment, overpayment/customer credit, refund/credit note and adjustment must reconcile across Dashboard, Invoices, Invoice Details and Account Statement.

### Commerce
Price/stock/MOQ are revalidated server-side. Out-of-stock, price changes and credit-limit violations cannot be bypassed by a forged client.

### Isolation
Account/store/B2B authorization is enforced server-side. Forged IDs or deep links cannot leak another customer's orders, invoices, statements or pricing.

### Localization
All 13 screens pass Arabic RTL and English LTR; state labels come from stable codes rather than business logic depending on translated display text.

### State completeness
Each screen implements appropriate loading, empty/no-results, error and stale/disconnected states. Retry must be visible where recovery is possible.

## Final E2E gate — #873

The C13 umbrella may close only after #873 passes on integrated `main`.

Required integrated journey:
1. Screen 1 login.
2. Screen 2 Dashboard shows authoritative account state.
3. Screens 3/4 explain purchase history.
4. Screens 10/11 browse and select a valid product.
5. Screen 12 creates one valid order.
6. Screens 7/8 show the same order and lifecycle.
7. Screens 5/9 show the resulting invoice and payment state.
8. Screen 6 shows the matching ledger movement/running balance.
9. Screen 13 exposes the account/finance/navigation hub and logout.

Financial acceptance must also prove:
- partial payment updates Screens 2/5/6/9 consistently;
- overpayment changes direction to **company owes customer / لك**;
- credit limit remains distinct from balance;
- an over-limit account-credit checkout is rejected server-side;
- refund/return/credit note/adjustment reconcile;
- currency is consistent and authoritative.

The final gate records final main SHA, route/action inventory, financial reconciliation evidence, test names, CI state, and any generated artifact version if C13 produces an artifact.

## Mission command

The repository-owner command is simply:

```text
C13
```

It means: reconstruct umbrella #858 and children #859-#873 from live GitHub state and continuously drain the mission under `AGENTS.md` until #858 is COMPLETE or every remaining incomplete lane is genuinely HUMAN-GATED.

A worker must not answer "C13 is finished" merely because one screen, one PR, one release, or one subset is green.
