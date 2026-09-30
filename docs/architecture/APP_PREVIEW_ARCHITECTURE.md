# FOODEX Live Application Preview Architecture

Status: **Authoritative**
Parent delivery: #498

This document defines the mandatory architecture for Dashboard-hosted previews of the real FOODEX Customer App and Driver App. The preview is a runtime view of the same application contracts and presentation behavior; it is not a visual mock, screenshot replica, or parallel HTML implementation.

## 1. Scope

The Preview Platform covers:
- Customer App Wholesale / B2B marketplace.
- Customer App Retail / B2C storefronts.
- Driver App B2B and B2C modes.
- Guest and authenticated preview personas.
- Draft and Published application-visible configuration.
- Live invalidation from Dashboard editors.
- Runtime/contract diagnostics, version compatibility and parity testing.

## 2. Non-negotiable runtime invariant

A Dashboard preview must reuse the real application runtime boundary wherever technically possible.

Required:
- Laravel remains the source of truth for business data, authorization, tenancy, pricing, catalog, orders and driver lifecycle.
- Existing production `/api/v1` contracts are reused before any preview-specific endpoint is introduced.
- Customer and Driver presentation logic is shared through Flutter packages/widgets/state adapters or an embedded Flutter runtime.
- StoreContext, channel, routing/deep-link semantics, localization and capability rules are the same rules used by the real apps.
- Native-only capabilities may use an explicit simulation adapter, but the state and API/action contract must remain the real contract.

Forbidden:
- Dashboard-only HTML copies of Flutter screens.
- Duplicated pricing, authorization, visibility, order-status or navigation rules.
- Fake runtime data presented as live preview data.
- Preview-only business APIs that reimplement Customer or Driver behavior.
- Hard-coded banners, products, stores, statuses or CTA targets.

## 3. Runtime topology

```text
Dashboard Editor
  -> Draft/Published configuration revision
  -> Preview session (optional persona)
  -> Embedded/shared Flutter preview runtime
  -> Existing authoritative /api/v1 contracts
  -> Laravel policies/domain services/StoreContext
```

Normal application runtime consumes the same Published revision and the same business APIs.

The preferred implementation is Flutter Web or a shared Flutter package/runtime that can be embedded in the Management Dashboard. If platform constraints require an adapter, the adapter must translate platform capability only; it must not duplicate business behavior.

## 4. Preview session security

Authenticated preview never requires or exposes a customer/driver password.

A preview session is short-lived, revocable and auditable. It contains or resolves:
- admin actor identity;
- target persona identity;
- channel;
- authorized store scope;
- preview mode;
- expiry;
- immutable audit correlation ID.

Rules:
- A preview credential cannot be accepted as a normal Customer or Driver production login token.
- Creating, using and revoking a preview session is audited.
- Server-side authorization is evaluated on every request; client-supplied `store_id` is never authority.
- B2C Store Admin preview scope is limited to assigned stores.
- B2B_ADMIN does not inherit B2C access.
- SUPER_ADMIN cross-store support preview is explicit and auditable.
- Driver preview never crosses B2B/B2C or Retail store assignment boundaries.

Default authenticated preview is read-only/safe. Destructive or commercial mutations are blocked/intercepted while their controls may still be rendered for parity. End-to-end mutation is allowed only for explicitly marked QA/Test identities under a separate approved contract.

## 5. Draft and Published revisions

Application-visible Dashboard configuration that participates in preview must resolve through a revisioned contract.

Minimum revision metadata:
- revision ID;
- channel/scope;
- optional store ID;
- status: draft, published or archived;
- schema version;
- parent revision ID;
- creator and timestamp;
- publisher and timestamp.

Publishing:
1. validates schema and cross-entity targets;
2. checks runtime/schema compatibility;
3. creates or promotes an immutable published revision;
4. records actor, time and diff/audit metadata;
5. makes the exact revision available to normal app runtime.

Rollback selects a previous valid published revision where the owning configuration model supports rollback. Production reset is not part of preview implementation.

## 6. Live update contract

Dashboard edits update Draft Preview without requiring a hard refresh.

Use the platform's approved broadcast mechanism (WebSocket/SSE/Laravel broadcasting). Events are scoped by channel, store and revision.

Recommended invalidation events:
- `storefront.preview.updated`
- `app.preview.configuration.updated`

Events should normally carry an invalidation/revision signal, not a duplicated business payload. The preview refetches from the authoritative API. Reconnect failures must expose stale-state and retry status instead of silently showing outdated content.

## 7. Customer App parity

Wholesale and Retail preview paths use the same Customer App contracts for:
- hero/banner composition and CTA targets;
- categories, products, offers and search entry;
- pricing visibility and customer-specific pricing;
- cart/session state;
- authentication gates;
- account/orders/invoices where the real route exposes them;
- localization and RTL/LTR;
- StoreContext and retail isolation.

Guest preview must not expose authenticated customer data.

Authenticated Customer preview must reflect the selected customer's real allowed state, including B2B eligibility/tier and exact Retail materialization, without weakening tenant ownership.

## 8. Driver App parity

Driver preview uses the same Driver App contracts for:
- B2B_DRIVER and B2C_DRIVER mode;
- status cards and assignment counts;
- assignment lists/details;
- invoice/order summary visibility;
- permitted status transitions;
- notes, proof-of-delivery and failed-delivery reason controls;
- location/navigation capability state;
- notification/reminder surfaces represented in-app.

Preview must not define an alternative delivery lifecycle. Existing driver lifecycle contracts remain authoritative.

## 9. Dashboard Preview Center

The Dashboard exposes a unified Preview Center with explicit controls for:
- Customer vs Driver;
- Wholesale vs exact Retail store;
- B2B vs B2C Driver;
- Guest vs selected authenticated persona;
- Draft vs Published;
- AR/EN and RTL/LTR;
- supported device profile;
- runtime/app/config schema version;
- connection/stale status.

Dashboard editors for banners, branding, home sections, promotions, Live Ads and app-facing settings should deep-link into the Preview Center with the correct context.

## 10. Preview Inspector

The embedded runtime exposes sanitized diagnostics sufficient to diagnose parity/runtime failures:
- component key/name;
- route;
- channel;
- safe store/entity identifiers;
- config revision;
- API endpoint and last status;
- loaded timestamp;
- persona mode;
- locale/device profile;
- Draft/Published state;
- CTA/action target;
- feature/capability flags;
- runtime/app/config versions.

Diagnostic export must redact credentials, secrets, request/response bodies containing sensitive data, unnecessary PII and precise location. It should align structurally with Customer/Driver/System inspector conventions without merging their responsibilities.

## 11. Version compatibility

Preview surfaces:
- preview runtime version;
- Customer App contract/build version;
- Driver App contract/build version;
- config schema version.

If Dashboard configuration requires a newer incompatible runtime/schema, Preview shows an explicit incompatibility state. Publish must be blocked when the change is not backward compatible and the required application/runtime contract is not available.

## 12. Failure states are product states

Preview must faithfully expose:
- no banners/stores/products/assignments;
- expired/revoked preview session;
- 401/403;
- inactive store;
- unavailable product;
- invalid CTA target;
- broken media;
- network failure;
- stale draft/live channel loss;
- runtime/schema mismatch.

Do not hide these states behind fallback data that makes the preview appear healthy.

## 13. Future-change parity rule

Any future Customer App or Driver App change that is Dashboard-managed or changes app-visible configuration/state must preserve preview parity in the **same PR**.

A change is incomplete when it updates only Dashboard or only Mobile and creates behavior that the other surface cannot reproduce from the same contract.

Required Definition of Done for affected changes:
- real app/shared runtime updated;
- Preview parity updated through shared code/runtime, not duplicated UI;
- API/OpenAPI updated when a contract changes;
- Draft/Published behavior validated when configuration is revisioned;
- Guest and authenticated variants validated when applicable;
- B2B/B2C and store isolation validated;
- AR/EN and RTL/LTR checked;
- route/action parity checked;
- runtime/config compatibility checked;
- parity/regression coverage updated.

## 14. Testing contract

Backend:
- preview-session authorization, expiry and revocation;
- impersonation audit;
- channel/store isolation;
- Draft/Published resolution;
- publish/rollback rules;
- live invalidation scoping.

Customer Flutter:
- Wholesale and Retail parity;
- Guest/authenticated variants;
- tier/store pricing;
- routes/deep links;
- RTL/LTR and supported widths.

Driver Flutter:
- B2B/B2C scope;
- status cards/assignments/details;
- lifecycle action visibility;
- proof/failure flows;
- RTL/LTR.

Dashboard:
- persona/store/channel selection;
- Draft/Published comparison;
- mutation blocking;
- expired/forbidden states;
- inspector/version warnings.

End-to-end parity should cover at minimum:
1. Guest Wholesale home.
2. Authenticated Wholesale customer with personalized state.
3. Guest Retail Store A.
4. Authenticated customer in Retail Store A.
5. Retail Store A vs B isolation.
6. Draft configuration change visible only in Draft Preview.
7. Publish makes the same revision visible to normal app runtime.
8. B2B Driver assignments.
9. B2C Driver Store A isolation.
10. Deterministic Draft vs Published comparison.

Visual regression should use shared Flutter fixtures/goldens or equivalent runtime screenshots across representative viewports and RTL/LTR. Differences are acceptable only for documented native-shell capabilities.

## 15. Integration authorities

This architecture must remain consistent with:
- `docs/architecture/SYSTEM_ARCHITECTURE.md`
- `docs/architecture/MULTI_TENANT_ARCHITECTURE.md`
- `docs/architecture/ROLE_ARCHITECTURE.md`
- `docs/architecture/PLATFORM_CUSTOMER_COMMERCE.md`
- Driver lifecycle/status contracts referenced by #413 and #438.
- Customer Marketplace Home contract #489 when home-layout behavior conflicts with older work.

Implementation tracks under #498 may be split across workers, but shared foundation files, OpenAPI, auth/session code, migrations and app bootstrap remain coordination-sensitive.
