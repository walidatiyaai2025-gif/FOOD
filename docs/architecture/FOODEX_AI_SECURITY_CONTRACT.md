# FOODEX AI Identity, Tenant and Retailer Sell-Out Security Contract

Status: **Authoritative implementation contract**  
Program: **AI-PROGRAM / #2208**  
Issue: **#2211 / AI-03**  
Parent architecture: `docs/architecture/FOODEX_AI_AGENT_ARCHITECTURE.md`

## 1. Purpose

This contract implements the identity and authorization boundary required by the FOODEX agent architecture. It extends the existing Assistant stack. It does not create a second authentication system, a second tenant resolver, a second tool registry, or a direct model-to-database path.

## 2. Server-derived AI principal

Every Assistant message turn creates an `AiPrincipalContext` through `AiPrincipalContextResolver`.

The context is derived from the authenticated FOODEX `User`, active roles, effective permissions, `OperationalTenantScope`, validated Assistant page context, and current retailer-data grants.

Authoritative fields are:

- authenticated actor user id;
- AR/EN locale;
- effective role codes;
- effective permission codes;
- business scope: platform, wholesale, retail, mixed, or unscoped;
- active channel and store when selected;
- server-derived permitted store ids;
- validated page context hints;
- active retailer sell-out grants available to the principal;
- request correlation id.

Browser/page ids, model output, conversation memory and provider suggestions never grant authority. Tool execution re-resolves the principal at execution time.

## 3. Tool execution boundary

`AssistantToolExecutor` is the production execution gate for the existing `AssistantToolRegistry`.

The Management Assistant controller no longer invokes registered tools directly. For every call the executor:

1. resolves the current active FOODEX user;
2. rebuilds principal/store/channel scope server-side;
3. rejects a scope with no current `assistant.use` authority;
4. applies exact retailer sell-out policy when the requested dataset class is `SELL_OUT`;
5. writes a safe `assistant.tool.read` audit event;
6. only then invokes the allowlisted registered tool.

Existing business/operations services continue their own domain-permission intersections. The central gate is additive defense and does not replace those checks.

Future orchestrator work must use the same execution gate rather than calling `AssistantToolRegistry::get(...)->execute(...)` directly.

## 4. Retailer sell-out sharing

Retail sell-out is protected retailer data. Wholesale sell-in does not imply permission to read sell-out.

Sharing state is persisted in `assistant_retailer_data_grants`. A grant is exact and contains:

- retailer store;
- grantee Wholesale store, which must be the canonical `WholesalePrincipal` store;
- basis: `INTERNAL`, `OPT_IN`, or `CONTRACT`;
- purpose, default `assistant_analytics`;
- active/revoked status;
- retention days;
- start and optional expiry;
- granting and revoking actors/timestamps.

A grant can exist only for an active Retail/B2C store with the existing authoritative `retail_wholesale_accounts` link and an active Wholesale/B2B store.

Retail users with current `assistant.use` + `reports.view` scope may read their own store sell-out without a wholesale-sharing grant. A wholesale principal must satisfy all of the following on every read:

- current `assistant.use` access to the canonical FOODEX Wholesale Principal store;
- current `reports.view` access to that same canonical Wholesale Principal store;
- existing Retail-to-Wholesale account relationship;
- active grant for the exact retailer, Wholesale store and purpose;
- grant start already effective;
- grant not expired;
- grant not revoked.

No wildcard retailer grant exists. Multi-retailer aggregation must authorize every retailer independently. If any member is unauthorized, the aggregate request fails closed.

## 5. Revocation and retention

Revocation updates the authoritative grant row immediately. Every subsequent sell-out authorization query reads current grant state, so conversation memory or a previously successful turn cannot preserve access.

Grant/revoke/read events are written through `AuditLogger`:

- `assistant.sellout.consent.granted`
- `assistant.sellout.consent.revoked`
- `assistant.sellout.read`
- `assistant.tool.read`

Required audit history remains in the existing audit log even after access is revoked.

`retention_days` is policy metadata for downstream #2213/#2216/#2226 consumers; it never extends authorization past revocation or expiry.

## 6. Leakage and denial behavior

Cross-store and hidden-id access fails closed through the existing 404-style store authorization boundary.

Sell-out consent denial uses a generic localized 403 message and never echoes the retailer id, Wholesale store id, grant id, hidden relationship details or policy internals.

English denial:

> Retail sell-out data is not shared for this Assistant request.

Arabic denial:

> بيانات البيع للمستهلك غير متاحة للمساعد لهذا الطلب.

## 7. Provider boundary

The full `AiPrincipalContext` is a server-side authorization object. It is not automatically inserted into provider prompts.

A provider may receive only minimized reasoning context selected by later orchestrator work. Role tables, complete permission maps, hidden tenant ids, access tokens, grant internals and audit data are not provider authority and must not be exposed as such.

## 8. Tests and acceptance

`AssistantSecurityBoundaryTest` covers:

- server-derived principal role/permission/store scope;
- cross-store IDOR rejection;
- Wholesale sell-out denied without consent;
- linked active grant success;
- immediate revocation denial;
- per-retailer authorization for aggregate requests;
- AR/EN generic denial without identifier disclosure;
- centralized tool reauthorization before tool execution;
- protected-read audit events.

Existing Assistant API, business and operations tests remain regression coverage.

## 9. API/OpenAPI impact

#2211 adds no new public `/api/v1` HTTP route and changes no existing external request/response schema. `docs/api/openapi.yaml` therefore requires no delta for this issue.

Consent persistence and policy are internal Laravel/domain contracts. A future business-facing consent-management HTTP surface must define its own explicit API/UI contract and authorization before exposure.
