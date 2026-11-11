# V2 T087R04 — Customer Service discount request / independent review

Issue: #2824 (T087R04), parent #2503. Base: **Foodex_V2 only**. Cross-reference #2853/P129 for profitability, credit, and independently approved below-floor policy.

## Existing authority and navigation (no duplicate screen)

- Domain/entity: Operations → Orders / existing pending customer order.
- Personas: authorized assigned Customer Service Agent (requester), Customer Service Manager with both orders.customer_service_discount and orders.approve and exact operational store scope (reviewer).
- User goals: request a justified percentage concession; approve it independently or reject with reason.
- Pattern: **Extend** the exact record drawer's existing Customer Service discount section, not a new route or Sidebar. Existing canonical navigation is Operations → Orders → order detail → Customer Service discount; responsive inline forms use the established FOODEX primary actions and 44px targets.
- Route: POST operations.orders.customer-service-discount (agent-only creates pending request; existing authorized managers retain direct legacy flow); POST operations.orders.customer-service-discount.review (independent review).
- Context: current order, store, channel and actor. No store IDs entered in UI. AR/EN catalogs, RTL/LTR inheriting the existing drawer, wrapped forms, no new navigation identity.
- Server rights: active actor; managedOrder('orders.view') canonical tenant concealment; scoped orders.customer_service_discount. Reviewer additionally requires orders.approve, separate operational store assertion and non-self approval. Assigned-only agent is verified at HTTP boundary.
- Durability: request table holds requester, order, percentage, reason, opaque UUID idempotency, pending/approved/rejected and reviewer/reason/timestamp, with unique (order, UUID). No pending request may silently edit totals or issue a notification. Approval calls the pre-existing **transactional** discount service, which independently rechecks pending order, invoice, paid/settled/dispatched state, tax snapshot, no-zero-value writeoff, pricing revisions and idempotency. Rejected and duplicate reviews never change totals or notify. Request/review decisions are audited.
- Tenant separation: read model is store/channel-scoped at request and review and the reviewer needs the correct store on both permissions. No privilege from a UI-visible request. Reject/approve independently enforced via HTTP.
- Data preservation: add-only non-destructive migration; no change to historic discount revision or .env, users, uploads, financial records. No automatic rollback destroys request history.

## Focused acceptance / regression

- Assigned agent can submit an idempotent request without any pricing mutation.
- Manager (distinct actor) can view pending requests, approve exactly once, generating one normal audited discount revision; retries return conflict.
- Other agents cannot approve; self-review is denied even when a user acquires an approval role.
- Rejection requires reason, is audited and never mutates order prices.
- Status drift while waiting for approval fails existing authoritative service validation and rolls back the request transition.
- Arabic/English unauthorized HTTP tests and translations. Existing authorized legacy manager discount and Retail store-boundary tests retained.
- Mobile impact: **Customer** — no mobile API response fields, navigation, identities or order payload schemas change; post-approval follows the existing order update notification. **Driver** — no app/API changes and B2C DRIVER-only dispatch is unchanged. **Van** — no app/API changes and B2B VAN-only dispatch is unchanged. Do not add a cross-app ticket for unaffected surfaces.

## Blocked wider requirements — keep #2824 OPEN until accepted

**The owner-approved discount cap, role-specific delegated approval matrix, profitability floor, credit exposure and independent below-floor exception authority are not defined in #2824 or current runtime config.** Do not invent a percentage threshold, silently broaden discount permissions or weaken the separate P129 safeguards. Therefore this PR adds an authentic, testable and secure **agent-request → separate approval/reject** slice, but is **not certification of the complete T087R04 cap / maker-checker / profitability policy**.

The existing authorized manager/supervisor direct flow remains unchanged to preserve live contractual behavior pending an explicitly governed cap rollout. A future issue-scoped owner-approved change must apply a declared cap consistently at the server's direct-apply and reviewer paths, test cross-role bypasses, and close #2824 only after full E2E and browser/mobile-web acceptance. Do not equate CI green with approved financial policy.
