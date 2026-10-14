# FOODEX 1.0.79 — WhatsApp CRM GA Release Notes

## Release identity

- Dashboard / repository release: `1.0.79`.
- Customer source identity: `1.0.79+79`.
- Driver source identity: `1.0.79+79`.
- Van source identity: `1.0.79+79`.
- WhatsApp CRM certification: `FOODEX-WHATSAPP-CRM-GA-1407-V1`.
- Certification matrix: 20/20 PASS with zero unresolved P0/P1 risks.

## WhatsApp CRM GA scope

- Provider-neutral WhatsApp architecture with the Meta Cloud adapter, durable webhook inbox, queued outbound transport, bounded retry, failure visibility and idempotency.
- Dynamic connection profiles with encrypted credentials, health validation, atomic primary-number switching and RECEIVE_ONLY / DRAIN migration behavior.
- Customer Service Agent/Supervisor RBAC, assignment/reassignment, unassigned queue, shared inbox, internal notes, tags, SLA and Customer 360.
- Dashboard-global inbound-message popup for the responsible Customer Service user, including exact-conversation deep link, RBAC/store isolation and replay deduplication.
- Optional transactional notifications for order, invoice, payment, refund and cancellation events.
- Template registry, segmentation, campaigns, consent ledger, opt-out suppression and campaign metrics.
- Supervisor analytics, lead state, follow-ups and unified customer timeline.

## Production safety

- The module remains `DISABLED` by default after deployment.
- The emergency outbound stop remains authoritative and is re-engaged during GA_READY certification.
- Certification never performs production activation.
- Production `ENABLED` still requires a separate explicit authorized Super Admin action after a valid connection profile is configured/tested and the emergency stop is deliberately cleared.
- Core FOODEX boot, orders, payments, customers, migrations and release processing do not require Meta/WhatsApp credentials.
- WhatsApp/provider outages remain isolated from core FOOD transactions.

## Additional Dashboard scope

- Include #1433 searchable single-product Sales Control instead of expanding all products, preserving strict store/RBAC scoping.

## Deployment and rollback

- This release contains additive WhatsApp CRM database migrations.
- The generated Dashboard update must include the migrations and all WhatsApp runtime/configuration files.
- Default-disabled behavior is the first rollback safeguard: deploying 1.0.79 does not create outbound WhatsApp traffic by itself.
- If code rollback is required, use the normal FOODEX updater/recovery path and preserve conversation/audit data unless an explicit data-removal procedure is separately approved.
- Customer, Driver and Van identities are synchronized for the release contract; no new mobile product behavior is introduced by the WhatsApp promotion.

## Validation contract

- Repository Policy, Required CI, localization, backend full tests, MySQL/Redis production acceptance, Store Readiness, Update Bundle validation and required visual evidence must pass on the exact promotion head.
- The final merge to `main` must be exact-head and may not bypass any failing release gate.
