# FOODEX V2 #2533 — P203 Campaigns runtime navigation authority

## Placement and canonical destination
- Owner source: FOODEX Unified RBAC / ERP / Sidebar Discussion, 2026-11-09, P203; parent #2503.
- Domain: Customer & Sales / Marketing; entity: WhatsApp campaign; actors: authorized Customer Service Manager/Agent/Supervisor and Super Admin; user goal: reach the real campaign management workbench only when ready.
- Reuse existing Dashboard Marketing group and route `admin.whatsapp-campaigns.index`; no new route, tab, menu, controller, visual component, permission engine, data flow or mutation. Navigation type: SIDEBAR; entry point: Marketing → Campaigns; fallback: existing Dashboard/Customer Service workspace. Desktop/mobile Web reuse the same authorized Sidebar/off-canvas tree; AR/EN use existing `whatsapp.campaigns.nav_title`, RTL/LTR unchanged.
- Before this fix, Customer Service Dashboard profile bypassed `RuntimePolicy::allowsCampaignManagement()` by calling `routeItem()` directly. All other Dashboard profiles used `whatsAppCampaignsItem()` and respected the same policy as `whatsapp.capability:campaigns` HTTP middleware. A disabled capability/emergency stop yielded a visible dead link to 404.
- Correction: every Dashboard persona uses existing `whatsAppCampaignsItem()`; the existing helper gates operational lifecycle, emergency stop, outbound + campaigns capabilities, and `whatsapp.campaigns.view` permission before projecting the actual route. The server middleware/controller still enforce independent authorization on direct HTTP requests. Display is not an authorization grant.

## Scope and security
- No new ACL or privilege/role grant, account mutation, store override, tenant channel, database migration, scheduler, queue, campaign send, notification or API surface. Runtime WhatsApp policy remains authoritative and fail closed.
- Existing `WhatsAppCampaignTest` covers the campaign index and real WhatsApp operation/scheduling policy. Focused `V2CampaignsRuntimeNavigationContractTest` tests Customer Service profiles under AR/EN RTL/LTR with disabled campaigns versus enabled destination HTTP 200, and disabled lifecycle/emergency-stop negatives returning HTTP 404. Tests use synthetic `whatsapp.campaigns.view` grants in a transient database only, not the production role registry; read operations do not mutate campaigns.
- Existing marketing campaigns, customer consent, audience/store filtering and campaign job execution remain unchanged and retain their existing independent HTTP/backend authorization tests. No alteration to canonical B2B=Van-only / B2C=Driver-only fulfillment.

## Cross-application impact
- Customer App: no API/permission/token/cart/order/marketing delivery contract changes; WhatsApp Dashboard navigation only.
- Driver App: no driver login, assignment, order, wallet or B2C dispatch changes.
- Van App: no Van visit, route, warehouse, B2B dispatch, offline replay or login changes.
- No Flutter changes or mobile build requested. Existing Dashboard shell and responsive/off-canvas layout are reused without new markup. Real-browser/owner UAT remains deferred to #2503/#2750; no screenshot artifact required.

## Cross-worker ownership fence
- #2713 / PR #3064 owns the unrelated AdminNavigation.php catalog inventory specialist hunk. #2533 changes only the Marketing WhatsApp campaigns condition and creates two new task-only files. Preserve both when syncing upstream; no force push or second PR for #2713.

## Delivery evidence
- One branch `fix/2533-v2-whatsapp-campaign-navigation-runtime`; PR base `Foodex_V2` only.
- Before closure verify exact-final-head Repository Policy, FOODEX V2 Isolation and Required CI terminal success (Laravel test suite, PHP/Pint/PHPStan/security/OpenAPI, AR/EN, MySQL/Redis clean install/upgrade/recovery when scheduled). Verify merge SHA against isolated branch. Manual acceptance: DEFERRED_TO_V2_FINAL. Never main, tags, production deploy or release.
