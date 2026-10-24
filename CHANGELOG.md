# Changelog

## 1.0.107 - 2026-10-24
- Make FOODEX Qwen3/OpenAI-compatible Assistant requests low-latency by sending `reasoning_effort: none` for provider health, capability probes, routing, planning and grounded synthesis while retaining deterministic fail-safe behavior.
- Fix Dashboard > FOODEX Assistant Settings provider testing so the agent-capability check validates Chat Completions, structured JSON arguments and tool calling in one forced-tool request instead of three back-to-back requests that could trigger provider HTTP 429.
- Stop immediate retries of provider HTTP 429 responses so FOODEX does not amplify throttling; existing safe fallback/circuit behavior remains in control.
- Add Assistant response provenance to current replies and conversation history: tool/evidence-backed business answers are labeled `Database / قاعدة البيانات`, while successful model-only replies are labeled `AI / ذكاء اصطناعي`.
- Preserve RBAC, store/channel scope, read-only tool enforcement, server-authoritative business data and the rule that chain-of-thought/reasoning is never exposed or persisted.
- Publish this as a cumulative Dashboard-only 1.0.107 update on top of the 1.0.106 lineage; Customer, Driver and Van Android identities remain unchanged.

## 1.0.106 - 2026-10-24
- Carry the Public Wholesale Web polish from the post-1.0.105 source lineage: Alexandria Google Font, 1560px widescreen shell, polished accessible hamburger drawer, larger branded image fallbacks, and canonical FOODEX icon usage across header/footer/favicon/catalog fallbacks.
- Fix the public Customer App download CTA so `/downloads/apps/customer/latest.apk` no longer returns HTTP 503 while the server-side APK mirror is still warming.
- Prefer the verified local APK mirror when ready; otherwise queue mirror preparation and immediately redirect the visitor to the immutable official GitHub Release APK for the exact Android policy version.
- Keep Customer, Driver and Van Android identities unchanged at `1.0.104+104`; this is a Dashboard/Public Web-only update and does not rebuild mobile APKs.
- Preserve version-locked download routing, local mirror integrity checks, cumulative updater compatibility and production API `https://foodex.50sols.com`.

## 1.0.105 - 2026-10-24
- Promote the authoritative Dashboard/backend lineage through `5efd2aaa9a72e72ee564d1a9dbd7d5328e28e895` as the FOODEX 1.0.105 dashboard-only update.
- Include the completed Wholesale storefront separation from #2394 / PR #2396: Customer App storefront editing remains isolated on the mobile channel while Public Wholesale Web has its own `b2b_web` draft, preview, sections, banners, settings, publish/discard flow and enable state.
- Keep the public Wholesale Web price-free and purchase-free while preserving management login, FOODEX branding, SEO metadata, brands/categories/products presentation and app-directed purchasing.
- Build the cumulative Dashboard Update package from the exact 1.0.105 source lineage using the canonical FOODEX Update Bundle workflow.
- Do not rebuild or version-bump Customer, Driver or Van APKs for this dashboard-only promotion; their source identities remain `1.0.104+104`.
- Preserve production API `https://foodex.50sols.com`, updater recovery safeguards, cumulative upgrade compatibility and manifest/checksum validation.

## 1.0.104 - 2026-10-23
- Promote the authoritative `main` lineage through `abdf0c0b799887fb5720034cc9a8187a140a40d1` as FOODEX 1.0.104; owner-selected 1.0.103 remains unpublished.
- Carry the completed 1.0.102 self-healing Dashboard updater hardening, including retry-safe migrations, full application-owned runtime packaging, explicit failure diagnostics, and safe handling of optional exact runtime files.
- Include the Marketplace mockup foundation from #2381, Retail branding/banner convergence from #2387, and authoritative Retail discovery with read-only owner browsing plus existing self-purchase protection from #2389.
- Include the production MySQL legacy `driver_current_locations.received_at` normalization from #2382 so strict MySQL can create the freshness index safely.
- Include the public FOODEX Wholesale web landing from #2391 with Dashboard-controlled enable/disable, price-free catalog/product/brands browsing, app-directed purchasing, and canonical FOODEX green identity.
- Include Catalog single-surface inline operations from #2384 for product fields, stock quantity, low-stock threshold, status and app visibility with warehouse scope, authorization and audit enforcement.
- Rebuild the Dashboard cumulative update, Laravel Setup, Customer, Driver and Van Android artifacts from the exact 1.0.104 release lineage; synchronize all mobile identities to `1.0.104+104`.
- Preserve the production API endpoint `https://foodex.50sols.com` and the canonical release validation, provenance, checksum and fresh-install gates.

## 1.0.102 - 2026-10-22
- Promote the latest authoritative `main` lineage through `e6fccacb53919610bce36e9c9efd54c3583259ec` as FOODEX 1.0.102.
- Include the completed Wholesale Customer UI polish from #2360 / PR #2361: Home product-card sizing control, authenticated header account controls, guest-action hiding, simplified More shortcuts with Customer Service contact, Company Data cleanup, store-branding account image, and compact-card overflow fixes.
- Rebuild the Dashboard cumulative update and Customer Android application from the exact 1.0.102 release source.
- Keep Driver and Van functionally unchanged; only synchronize their release identities to `1.0.102+102` and rebuild their Android artifacts from the same exact source lineage.
- Preserve the production API endpoint `https://foodex.50sols.com` and canonical release validation, checksum, update-manifest and provenance gates.

## 1.0.95 - 2026-10-20
- Add SUPER_ADMIN Customer 360 identity editing for customer name, email and phone, keeping the canonical User, Platform Customer, legacy customer and B2B/B2C customer records synchronized.
- Add Customer App password provisioning/reset directly from Customer 360, including account activation, previous Sanctum session revocation and auditable security events.
- Require a real valid customer email before mobile-password provisioning so imported/synthetic contacts cannot be enabled accidentally.
- Add regression coverage proving an imported inactive customer can be provisioned from the Dashboard and then authenticate through the production Customer App login API, while non-super-admin identity edits remain forbidden.
- Promote the synchronized FOODEX release identity to Customer, Driver and Van `1.0.95+95` and rebuild the Dashboard update through the canonical Release Factory.
- Hotfix Catalog Product Management (#2290) so Active/Inactive can be changed directly from each grid row with lifecycle/audit preservation, and render full product names without truncation.

## 1.0.94 - 2026-10-19
- Promote the latest authoritative `main` lineage through `23adef9f67ae798a46e79733990b35d4357ba0e9` as FOODEX 1.0.94.
- Include the Customer mobile merchandising and finance presentation polish from #2261, including refreshed shopping/category/product presentation and customer-facing finance terminology.
- Include the ERP local/testing workspace activation fix from #2264 while preserving production deny-by-default behavior.
- Rebuild the Dashboard update, Laravel Setup, and Customer, Driver and Van Android artifacts from the same synchronized release source.
- Synchronize Customer, Driver and Van release identities at `1.0.94+94`; no prior APK is reused as the canonical 1.0.94 artifact.
- Keep the release package cumulative from the canonical updater boundary and preserve the production API endpoint `https://foodex.50sols.com`.

## 1.0.91 - 2026-10-18
- Promote the authoritative FOODEX main lineage after 1.0.90 with the Assistant Ollama/Qwen compatibility hotfix from #2204 / PR #2205.
- Normalize leading Qwen `<think>...</think>` reasoning wrappers and full markdown JSON fences before Assistant routing JSON is parsed, while preserving the existing intent allowlist and entity sanitization.
- Make the saved External AI provider connection test require the exact `FOODEX_OK` compatibility sentinel instead of accepting any non-empty model reply.
- Increase bounded Assistant provider completion budgets so short reasoning output cannot consume the whole response before the final routing JSON or probe sentinel.
- Preserve deterministic fail-safe fallback, read-only Assistant tools, RBAC and authoritative store/channel scope with no new write capability.
- Publish 1.0.91 as the direct cumulative Dashboard hotfix path for installed 1.0.90 systems; no full redeploy is required.
- Converge the final 1.0.91 release candidate on authoritative `main` through completed AI-PROGRAM production acceptance (#2208/#2232), so no later main change is dropped from the cumulative package.
- Keep the published 1.0.90 registry/update artifacts immutable in the release candidate; generate and register the 1.0.91 bundle through the canonical release distribution flow.
- Synchronize Customer, Driver and Van source identities at 1.0.91+91 per the release contract; the Dashboard update remains cumulative and independently deployable.

## 1.0.90 - 2026-10-18
- Promote the complete authoritative main lineage after FOODEX 1.0.89 into the cumulative Dashboard 1.0.90 update.
- Fix External AI provider API-key persistence on MySQL by storing encrypted ciphertext as a valid JSON string document while preserving encrypted-at-rest handling and safe decryption.
- Ship the latest shared global-header alignment across search, notifications and account controls.
- Ship the P0 Retail authentication repair that prevents one Retail login attempt from locking out unrelated accounts.
- Add authoritative B2C business profiles so Retail Store and Restaurant behavior can be separated by explicit server-side profile semantics.
- Preserve all earlier 1.0.89 enterprise, Customer Service, Restaurant SaaS and Assistant configuration capabilities already present on main.
- Synchronize Customer, Driver and Van source identities at 1.0.90+90 per the repository release contract; the Dashboard update remains independently deployable.

## 1.0.89 - 2026-10-18
- Promote every authoritative main change merged after FOODEX 1.0.86 through the current release head; owner-selected versions 1.0.87 and 1.0.88 remain unpublished.
- Ship Restaurant SaaS 2.0 management/template/content convergence, per-store entitlement, module-aware RBAC, WhatsApp OTP authentication, and completed enterprise Catalog, Procurement, Stores/Channels, Field Operations, System Administration and Security governance surfaces.
- Ship Customer Service / Customer 360 completion plus manager/team boundaries, order creation and auditable order-owner assignment with strict Retail/B2B isolation.
- Ship compact Retail/B2B/Admin dashboard and header convergence, platform-only Retail navigation isolation, audited user onboarding, and bilingual task-focused Security administration.
- Add configurable FOODEX Assistant external AI settings for OpenAI-compatible API URL, model, encrypted API key, timeout and connection testing while keeping deterministic read-only FOODEX tools and RBAC/store/channel authority.
- Synchronize Customer, Driver and Van release identities at 1.0.89+89; generate release artifacts only from exact-head green release validation.
- Keep the Dashboard package cumulative through the canonical updater boundary and independently deployable from installed mobile binaries.

## 1.0.86 - 2026-10-15
- Promote the complete post-1.0.79 Dashboard lineage after the final #1560 P0 Smart Ordering blocker closed on main.
- Ship the Premium Smart WhatsApp Chatbot plus WhatsApp media/calling/campaign/inbox and Meta-template reliability fixes while preserving fail-closed runtime controls.
- Ship the certified Smart Ordering GA platform: menu, checkout, customer growth/loyalty, WhatsApp bridge, QR attribution, analytics, merchandising and FOODEX supply intelligence.
- Add menu/category/item media visibility, draft review-before-publish, business-level QR presets and bundled TCPDF fallback for QR SVG/PDF generation.
- Include updater canonical SemVer repair so malformed installed versions such as `1.084` self-heal to `1.0.84`, and keep first-contact WhatsApp consent unknown until explicit evidence exists.
- Synchronize Customer, Driver and Van release identities at 1.0.86+86 and build release artifacts only from an exact-head green promotion commit.

## 1.0.79 - 2026-10-14
- Promote the independently certified WhatsApp CRM program from #1399/#1407 into the production release lineage with provider abstraction, secure inbound/outbound transport, dynamic connection profiles, Customer Service roles/assignment, shared inbox, Customer 360, transactional notifications, campaigns, consent/opt-out, supervisor analytics, leads/follow-ups and the Dashboard-global inbound-message popup.
- Carry certification `FOODEX-WHATSAPP-CRM-GA-1407-V1` with a 20/20 PASS matrix, zero unresolved P0/P1 risks, fail-closed source-controlled GA evidence, audited GA_READY promotion, and a separate emergency outbound stop.
- Keep WhatsApp disabled by default after deployment; no Meta token, WABA ID, phone-number ID, webhook token or signing secret is required for FOODEX boot, update, migration or unrelated business operation.
- Include the searchable single-product Sales Control from #1433 while preserving store/RBAC isolation.
- Synchronize Customer, Driver and Van source identities at 1.0.79+79 for the release contract without introducing new mobile product behavior.
- Ship the cumulative Dashboard update with the WhatsApp additive migrations and rollback-safe/default-disabled runtime contract.

## 1.0.78 - 2026-10-14
- Unify Dashboard order management on `/admin/operations/orders` while preserving strict server-side B2B/B2C channel isolation and store scope.
- Redirect legacy B2B and B2C order pages to the canonical Operations surface and remove duplicate order-management navigation entries.
- Keep contextual order actions under the three-dot menu, including permitted edit/re-price, status, B2B Van dispatch, B2C Driver dispatch/reminder, and finance-authorized invoice access.
- Add pending-order editing through the existing authoritative `AdminOrderManagementService`; paid, issued/locked, and non-pending orders remain non-editable.
- Add regression coverage for cross-channel denial, canonical legacy redirects, and contextual action rendering.
- Dashboard-only update: no database migrations and no Customer, Driver, or Van runtime source changes.

## 1.0.77 - 2026-10-14 — PRODUCTION HOLD

- Make FOODEX 1.0.77 the only valid next production-launch target; 1.0.76 remains historical evidence and is superseded for production launch.
- Preserve the Customer application exactly at frozen baseline `4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d`; 1.0.77 release composition must not modify `apps/customer_app/**`.
- Complete the real FOODEX catalog, image-fidelity reconstruction, inferred-value disclosure, verified pre-launch snapshot, reversible Production/Legacy switch and authoritative launch-report gates under #1380.
- Hold all final 1.0.77 publication until independent certification proves Customer zero-diff, exact Legacy restoration and successful re-launch from the integrated source.

## 1.0.76 - 2026-10-13

- Promote the frozen final Customer APK lineage from #1376 into synchronized FOODEX 1.0.76, preserving the approved customer runtime while carrying App Review and Smart Store Analytics integration.
- Include the completed Customer UX/runtime corrections on main: online-first networking with cache fallback, single biometric login flow, persistent Home navigation, three-column product grids, compact commerce/account/invoice surfaces, location-first address editing, Dashboard-managed store logo, and dimmed Shopping until store selection.
- Preserve authenticated Smart Store Analytics and APP-PREVIEW acceptance from the exact final Customer lineage without introducing additional Customer feature changes.
- Synchronize Customer, Driver and Van source identities at 1.0.76+76 and rebuild the Dashboard update and Android artifacts from the same exact release commit.
- Dashboard update targets direct upgrade from 1.0.75 while retaining the cumulative updater boundary and release validation gates.

## 1.0.75 - 2026-10-13

- Promote the authoritative FOODEX main lineage after the completed 1.0.74 Dashboard update into synchronized release 1.0.75.
- Preserve the full Customer VNext program and Mobile Smart Store Analytics stack already integrated on main, including Retail EGP analytics, Dashboard-managed store branding, compact mobile analytics UI, insight badge/pulse behavior, production launch controls, protected reset/reseed, and final certification gates.
- Rebuild the Dashboard update and Customer, Driver and Van Android artifacts from one exact release lineage with mobile source identity 1.0.75+75.
- Keep production Launch/reset safeguards fail-closed; release promotion does not bypass owner-data, asset, backup, migration or green-preflight requirements.
- Dashboard update targets direct upgrade from 1.0.74 while retaining the cumulative updater boundary.

## 1.0.74 - 2026-10-12

- Promote the completed Customer VNext program from umbrella #1323 into the FOODEX 1.0.74 Dashboard update, including the unified Shopping Home, catalog/search/product details, cart/checkout/order success, orders/tracking, account/address/notifications/offers/settings, sanitized launch-data baseline, protected reset/reseed, production preflight/Launch gate, and independent final certification from #1333 / PR #1353. The Dashboard package supports direct update from the currently published 1.0.73 release.
- Include the integrated Mobile Smart Store Analytics mission #1354–#1359: authoritative Retail analytics in EGP, live Dashboard-managed store branding, compact mobile analytics UI, unread insight badge/pulse accessibility behavior, explicit Retail-vs-Wholesale context isolation, and final security/runtime/visual convergence through PR #1366.
- Carry forward exact-head repository policy, localization, backend/MySQL, Customer validation, runtime screenshot, required mobile visual evidence, APP-PREVIEW parity and Platform Customer Commerce E2E gates from the merged integration lineage.
- Preserve fail-closed production safeguards: Launch/reset still require the configured owner data, approved high-resolution assets, backup and green preflight evidence; this release does not bypass those controls.
- Synchronize Customer, Driver and Van source identities at 1.0.74+74. The Dashboard Update ZIP remains independently deployable; APK installation remains an explicit operational action.

## 1.0.73 - 2026-10-12

- Promote the authoritative post-1.0.72 main lineage into the FOODEX 1.0.73 Dashboard update, supporting direct update from the currently installed 1.0.72 Dashboard.
- Ship the Dashboard Mobile Web charter and full admin-page rollout from PRs #1298 and #1303, including off-canvas navigation, compact phone composition, mobile record-card tables, responsive forms/dialogs/pagination and AR/RTL + EN/LTR acceptance at 320/360/390/412/430px.
- Include B2B navigation cleanup from PR #1305 by hiding Inventory Management and System Settings / Lookups from the shared sidebar while preserving the underlying authorized routes and functionality.
- Remove Softarkan design-credit branding from customer report PDFs through PR #1307 while preserving report pagination and adding regression coverage.
- Include Release Factory reliability repairs from PRs #1290, #1295 and #1299 so synchronized Dashboard + Customer + Driver + Van publication uses the canonical B2B Sales Control fresh-install fixture and clean generated-distribution branch handoff.
- Synchronize Customer, Driver and Van source identities at 1.0.73+73 for the release contract. The Dashboard Update ZIP remains independently deployable from installed APKs; mobile APK publication/deployment remains an explicit operational choice.

## 1.0.72 - 2026-10-11

- Promote all authoritative Dashboard/backend changes merged after FOODEX 1.0.71 through PR #1287 into the FOODEX 1.0.72 Dashboard update.
- Ship Wholesale/Retail commerce fixes from PRs #1268 and #1269, including optional Wholesale pricing, principal-stock behavior, Customer commerce UX hardening, and normalized EGP/unit display formatting.
- Improve Dashboard operations from PRs #1274, #1275, #1279 and #1285: customer-first order notifications, simplified B2B product/inventory navigation, short customer-facing order/invoice display numbers while preserving immutable internal references, and Sales Control moved to the authoritative B2B context.
- Include Van production fixes from PRs #1281 and #1283: remembered-session biometric unlock and authoritative Wholesale seller-store catalog resolution.
- Include engineering/release hardening from PRs #1271 and #1287, including safer Flutter authoring gates, CI-parity formatting validation, and path-aware Fast Release Pipeline v2.
- Synchronize Customer, Driver and Van source identities at 1.0.72+72 for the release contract; the Dashboard Update ZIP remains independently deployable and does not replace installed APKs unless mobile artifacts are explicitly published.

## 1.0.71 - 2026-10-11

- Promote the post-1.0.70 Dashboard changes merged through PR #1265 into a new immutable FOODEX Dashboard release.
- Add the three locked Customer 360 legacy-report replicas from Issue #1261 / PR #1262: customer statement, detailed item withdrawals and item withdrawals, with authoritative B2B data, exact Arabic layout conventions, browser preview/print and real PDF download.
- Fix Dashboard live Van visibility from PR #1265 by accepting authenticated Van GPS heartbeats, inheriting assignment/store scope, and keeping authorized Van runtime presence visible on the fleet map.
- Synchronize Customer, Driver and Van source identities at 1.0.71+71 for the release contract while keeping Android publication/installed-APK policy unchanged; the Dashboard Update ZIP does not replace installed APKs.

## 1.0.70 - 2026-10-11

- Promote the post-1.0.69 Dashboard order-notification work from Issue #1258 / PR #1259 into a new immutable FOODEX release version.
- Add the global Customer App new-order popup across authenticated Dashboard pages, with scoped incremental feed, queueing, session deduplication, direct order deep-linking, AR/EN support, visibility-aware polling and backoff.
- Preserve tenant/store/channel authorization and exclude orders created manually from Dashboard, Van or Integration sources from the Customer App popup.
- Synchronize repository/mobile source identities at FOODEX 1.0.70 / mobile build 1.0.70+70; the Dashboard Update ZIP remains independently deployable and does not overwrite APKs already installed on devices.

## 1.0.69 - 2026-10-11

- Promote every authoritative main change merged after FOODEX 1.0.67 through #1255 into the synchronized FOODEX 1.0.69 production release.
- Ship B2B Van-only fulfillment convergence, Customer low-data/network-storm protection, Dashboard-local APK mirroring with mobile-version policy decoupling, and the one-push-green CI foundation.
- Ship Customer app-wide responsive hardening for 320px screens, enlarged system text and RTL/LTR navigation/footer behavior.
- Build synchronized Dashboard Update, Customer, Driver and Van Android artifacts from one FOODEX 1.0.69 source commit with mobile identity `1.0.69+69`.


## 1.0.67 - 2026-10-09

- Publish the Van push-notification compatibility hotfix from #1166 so Driver-compatible Van sessions can register and receive authorized operational notifications without weakening Van/session isolation.
- Fix the Van B2B Product Catalog empty-state regression from #1168 by resolving catalog pricing through the canonical B2B price resolver: explicit tier pricing remains authoritative while eligible Platform Customers retain the active base Wholesale price fallback when a tier row is absent.
- Preserve store/channel/customer scoping and add regression coverage proving base-price fallback plus explicit tier price/MOQ/increment override behavior.
- Build synchronized Dashboard Update, Customer, Driver and Van Android artifacts from one FOODEX 1.0.67 source commit with mobile identity `1.0.67+67`.

## 1.0.66 - 2026-10-09

- Publish the #1163 Van production fix with the Customer-parity Van login composition while keeping the post-login Van business workflow unchanged.
- Fix authorized Driver-compatible Van finance/customer scope so collections and related financial actions resolve through the selected Van runtime context instead of actor ownership alone, while preserving tenant and Van isolation.
- Surface actionable Van finance API validation states instead of collapsing non-2xx failures into a generic error, and include regression coverage for Van login composition and same-Van Driver finance access.
- Build synchronized Dashboard Update, Customer, Driver and Van Android artifacts from one FOODEX 1.0.66 source commit with mobile identity `1.0.66+66`.

## 1.0.65 - 2026-10-08

- Promote every authoritative main change merged after FOODEX 1.0.64 into the synchronized FOODEX 1.0.65 production release.
- Ship the completed Merchant Intelligence integration from #1122 / PR #1136, including authoritative B2B price batching, retail inventory price snapshots, validated Wholesale source-store lineage, reorder-query elimination, Dashboard cart-validation batching, integrated Customer/Driver/Van journeys and catalog-size query-stability coverage.
- Build Dashboard Update, Customer, Driver and Van Android artifacts plus the Laravel first-install package from one release source commit with synchronized mobile identity `1.0.65+65`.
- Preserve production endpoint provenance and require green release/update validation before publication.

## 1.0.64 - 2026-10-08

- Promote the latest clean `main` convergence into FOODEX 1.0.64, including Dashboard pagination coverage, viewport-safe grid action menus, Retail-owner Flash audience lookup/targeting, geography reset/rebuild tooling, and the complete Egypt FOOD Van geography dataset.
- Build Dashboard Update, Customer, Driver and Van Android artifacts plus the Laravel first-install package from one release source commit with synchronized mobile identity `1.0.64+64`.
- Enforce binary endpoint provenance so every Android APK embeds `https://foodex.50sols.com` and rejects the obsolete `https://vanfoodex.50sols.com` endpoint.
- Require fresh-install evidence, synchronized release manifests, update-bundle validation, localization quality and the repository/mobile CI gates before publication.

## 1.0.61 - 2026-10-07

- Rebuild Customer, Driver and Van Android artifacts from the final main integration so their embedded production API endpoint is `https://foodex.50sols.com`.
- Reject release APKs at binary level if `https://foodex.50sols.com` is absent or the obsolete `https://vanfoodex.50sols.com` endpoint is present.
- Fix the Van Arabic mobile navigation shell: keep the hamburger on the physical left, open the sidebar as an overlay over the current dashboard, close it after menu selection, and prevent nested dashboard navigation.
- Preserve the remaining 1.0.60 application behavior and production configuration while correcting release provenance, endpoint embedding and synchronized mobile identity to 1.0.61+61.

## 1.0.60 - 2026-10-07

- Correct the UIUX-V42 Commercial / Sales Control regression discovered after the 1.0.59 fresh release: the route now uses the authoritative FOODEX premium Dashboard shell and sidebar instead of the legacy standalone commercial page.
- Rebuild Product Sales Control hierarchy for responsive Arabic RTL / English LTR operation with clear product policy sections for availability, channels, selling units, break-pack behavior, quotas and controlled advanced targeting data.
- Preserve the canonical server-authoritative commercial policy engine, permissions, store scoping, feature flags, audit behavior and existing persistence contracts while adding regression coverage for Dashboard-shell parity.

## 1.0.59 - 2026-10-07

- Publish the terminal UIUX-V42 mission release after #1002-#1012 convergence, preserving the accepted Customer, Driver, Van and Dashboard behavior on the exact frozen implementation lineage.
- Ship compact/full-width mobile UX parity, Remember Me and biometric unlock parity, Driver live order alerts/deep links, unified Driver + Van Live Tracking, the real Customer invoice surface, and final Arabic/English RTL/LTR localization acceptance.
- Build synchronized Customer, Driver and Van Android artifacts at 1.0.59+59 together with the Dashboard update package and the owner-downloadable Laravel Setup ZIP from one release source commit.
- Validate a completely clean installation from the exact generated Setup ZIP using empty MySQL/Redis state, create the first Super Admin, verify installer lock/version state and the final commercial/Flash/field-operations Dashboard surfaces.
- Publish the same Setup/APK/manifest bytes to the immutable GitHub Release, generated distribution branch and canonical terminal branch `release/1021-uiux-v42-final-real-build`, with SHA-256/source provenance checks.
- Keep `main` untouched by this mission release.

## 1.0.58 - 2026-10-06

- Consolidate the complete FOODEX Van field-operations release and #983 commercial program into the single canonical branch `release/1.0.58-van-complete`, without merging to `main`.
- Ship the canonical commercial policy engine with OPEN/RESTRICTED/CLOSED states, channel/schedule restrictions, independent selling units, base-unit authoritative inventory, quotas, break-pack rules, atomic reservations, audited overrides and Customer > group > channel/default precedence.
- Ship Flash Offers end-to-end with server-authoritative time, allocation/reservation expiry and release, idempotency, non-stackable defaults, Customer popup/push/Buy Now, audience targeting, Van push-only online behavior, feature flags and kill switches.
- Ship Van selected-customer commercial rules, quota/restriction reasons, explicit override, commercial offers and Flash online-only while preserving restricted inventory count/transfer/return/reconciliation operations.
- Ship Dashboard Product Sales Control, Flash management, popup/product-card/notification preview and Flash analytics; preserve cross-channel order/invoice/finance/warehouse snapshots and notification/deep-link dedupe.
- Ship Store Submission Readiness for Customer and Driver/Van Android/iOS, reviewer credential masking, legal/support/account-deletion surfaces, managed Driver/Van deactivation semantics, configurable version/footer visibility and deterministic PASS/WARN/BLOCKED audits.
- Target the clean production installation at `https://vanfoodex.50sols.com` with database identity `solscool_vanfoodex`; generate a fresh Laravel Setup ZIP with production Composer dependencies and validate it from an empty MySQL/Redis environment.
- Keep credentials, database passwords, private signing keys, Firebase service-account secrets and production store signing material outside Git.

## 1.0.57 - 2026-10-05

- Normalize the existing Customer client typography, spacing, badges, headers, order cards, financial summaries and contextual state surfaces in place without adding screens or changing routes/business behavior.
- Keep Arabic RTL and English LTR first-class, move remaining More/Profile labels into the existing localization resources, and render Dashboard timestamps through Flutter localization rather than raw ISO-derived text.
- Preserve store/backend-driven currency and all authoritative order, invoice, finance, pricing, permissions and API contracts while reducing overflow risk through controlled max-lines, ellipsis and compact responsive sizing.
- Make the Dashboard Driver map refresh silently after its initial load: keep the mounted map and last successful markers visible, suppress duplicate refreshes, preserve successful content on background failure, reserve status space to prevent layout shift, and dispose polling/network work when leaving the page.
- Add live-map regression coverage for silent refresh, preserved last-success content, duplicate-request suppression and page-leave cleanup; synchronize Dashboard, Customer and Driver identities at 1.0.57 / mobile build 1.0.57+57 and publish through the existing cumulative Dashboard update mechanism.

## 1.0.56 - 2026-10-05

- Publish the completed Catalog / Customer 360 / B2B Orders redesign and the #924 CI stabilization wave now merged through PR #925.
- Ship compact single-row Catalog product management with contextual actions, store-configured currency display, and preserved catalog/pricing/inventory business logic.
- Ship tabbed Customer 360 with Finance first, editable audited Wholesale credit limit, and map-based address location selection that stores coordinates automatically without exposing manual latitude/longitude inputs.
- Ship the B2B Orders create-order modal, improved Arabic workflow/payment labels, and cleaner order operations while preserving authoritative quote, stock, pricing, finance, permissions and routing.
- Include the associated reliability hardening for System Inspector, CSRF refresh, Customer/Driver retries and backoff, invoice PDF authorization/rendering, preview unavailable states, live polling cooldowns, and B2B finance query performance from the green #924 integration branch.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.56 / mobile build 1.0.56+56, while leaving production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.55 - 2026-10-05

- Promote the owner-approved Customer visual and functional convergence from #920 / PR #921: FOODEX reference login, single-screen Business Dashboard, compact Top Products, discoverable Wholesale Business Dashboard shortcut, redesigned More surface, and refreshed Orders/footer composition.
- Keep all business values, permissions, account/store entitlement, finance metrics, orders and navigation authoritative to the existing backend/API/session state with no hardcoded production business data.
- Preserve Arabic/English RTL/LTR behavior, Remember Me and biometric sign-in, exact Wholesale store context, Retail/Wholesale isolation, and the validated five-destination Customer navigation contract.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.55 / mobile build 1.0.55+55 without changing production minimum-version, force-update, Driver location-enforcement or Assistant activation settings.


## 1.0.54 - 2026-10-05

- Publish the final accepted C13 Customer journey after umbrella #858 and integrated gate #873 / PR #917, including the canonical 13-screen Arabic/English visual and operational convergence.
- Complete discoverable principal Wholesale recovery from Customer Dashboard/Shopping so an authenticated Wholesale customer can return from Retail context without logout, while preserving authoritative store/account isolation.
- Preserve authoritative Customer finance, purchasing power, checkout approval, invoice/order/driver collection contracts and centralized sanitized System Inspector reporting across Dashboard, Customer and Driver.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.54 / mobile build 1.0.54+54 without changing production minimum-version, force-update, Driver location-enforcement or Assistant activation settings.
- 1.0.53 was not promoted to authoritative main; 1.0.54 is the immutable final C13 distribution.

## 1.0.52 - 2026-10-04

- Publish the completed FOODEX operational-completion wave #828 after the final integrated #841 gate: gated Add Store and New Order popup wizards, central stable-code operational lookups, and the admin login default to the B2B dashboard.
- Ship the cumulative Catalog ZIP sample/preview/import flow for Products, Categories, Brands and images, plus server-authoritative OUT_OF_STOCK enforcement across API, Dashboard and Customer purchasing surfaces.
- Converge order lifecycle/status tabs and preserve Delivered history while removing completed work from active Driver queues; add complete Driver pickup/delivery details, Failed Delivery / تعذر التوصيل, and lifecycle-aware live Home card refresh.
- Publish Customer launch notification-campaign popups with authoritative eligibility/frequency/store-channel scoping, and Finance invoice From/To/customer filters with matching PDF and Excel exports.
- Publish current authoritative Customer and Driver App Review/Preview parity with visible freshness/stale/disconnected state and no fake live-data fallback.
- Preserve B2B/B2C/store isolation, AR/EN + RTL/LTR behavior and the green final integrated release gate; synchronize Dashboard, Customer and Driver release identities at 1.0.52 / mobile build 1.0.52+52 without changing production force-update or minimum-version policy.

## 1.0.51 - 2026-10-03

- Publish the approved Customer B2B visual convergence from #823 / PR #824: separate Home discovery from the full Products catalog, lock Wholesale surfaces to the FOODEx green identity, use readable two-column phone cards, surface Brand identity, and remove per-card cart shortcuts while preserving product-detail purchasing.
- Replace the raw Business Account profile payload with clear account, company/contact, linked-store, linked-wholesale-account, customer-profile, address and favorites summaries without changing Dashboard or backend authorization contracts.
- Improve Driver delivery execution: show the complete authoritative delivery address in Assignment Details and open Google Maps using the immutable navigation coordinates when present, with the saved address as the external-map fallback when coordinates are unavailable.
- Replace the Driver Home accepted-order pickup shortcut with the prominent Delivery Failed / تعذر التوصيل action; keep Picked Up / تم الاستلام available inside Assignment Details so the authoritative lifecycle remains intact.
- Unify Customer and Driver launcher artwork under the approved FOODEx Economical Group identity and make Driver local/FCM notification presentation resolve to the same launcher identity.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.51 / mobile build 1.0.51+51; keep production minimum-version, force-update, Driver fresh-location enforcement and Assistant activation settings unchanged.

## 1.0.50 - 2026-10-03

- Replace the fragile Wholesale marketplace product modal with a full product-details route that preserves exact store context and exposes product imagery, description, customer price, MOQ/order increment, stock information and add-to-cart behavior.
- Make Favorites functional across Wholesale and Retail product cards/details with authenticated customer-domain handling and preserved store/channel provenance, while keeping Retail detail behavior intact.
- Render four product cards per row on normal phone widths for both Wholesale and Retail, with a safe narrow-width fallback and regression coverage for overflow/preview parity.
- Repair Dashboard Catalog Management after the production /admin/catalog undefined-variable failure, keep product image/primary-image editing operational, and expose a direct authorized Catalog Management sidebar entry including SUPER_ADMIN.
- Add regression coverage for B2B product-detail routing, favorites, catalog rendering and mobile grid policy; Backend validation, Customer Flutter/iOS validation, MySQL/Redis acceptance, UI Visual QA and mobile screenshot gates are green for #817 / PR #818.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.50 / mobile build 1.0.50+50; keep production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.49 - 2026-10-03

- Prevent signed-in Retail owners/managers from reopening their own Retail storefront through stale remembered commerce context, the Marketplace store shortcut, the persistent footer, or direct Retail catalog routes; invalid own-store context now falls back to the allowed platform/Wholesale context while backend self-store purchase blocking remains authoritative.
- Make Dashboard media authoritative across the Customer catalog: Wholesale categories expose and render their category image, Product payloads retain primary product imagery, and Retail/Wholesale product contracts expose Brand names and Brand images with safe icon fallback when media is missing or fails.
- Render real Wholesale category and Brand rails from catalog data instead of fixed placeholder icons, and surface Brand imagery on Retail product cards without weakening store/channel isolation.
- Add immediate Dashboard image previews before save for Product multi-image uploads, Category images, and Brand images while preserving the existing saved-image thumbnails and validation rules.
- Add regression coverage for stale own-store header/footer navigation and category/Brand image API contracts, with Backend/MySQL, Customer Android/iOS, UI Visual QA and update-package validation green in #812 / PR #813.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.49 / mobile build 1.0.49+49; keep production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.48 - 2026-10-03

- Promote the owner-approved Customer APK baseline from #794 and preserve the final Home, persistent footer, store-switching and Dashboard-aware image behavior across the synchronized release.
- Complete the corrective Customer Commerce V4 closure: canonical Retail merchant platform identity and linked Wholesale account reconciliation (#797), authoritative own-store exclusion and backend blocking (#798), isolated Wholesale/Retail My Orders tabs with immutable store/channel provenance (#799), and coherent Dashboard primary-owner binding/reassignment (#800).
- Preserve one platform Customer login across Wholesale and Retail while keeping cart, checkout, order history, operational queues, notifications and driver routing authoritative to the originating store and channel.
- Include the final integrated corrective gate #775 / PR #805, with Backend/MySQL, Customer Android/iOS, Driver Android/iOS, runtime screenshot evidence, App Preview parity and legacy-route guards green against the final integrated main.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.48 / mobile build 1.0.48+48; retain production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.47 - 2026-10-02

- Publish all post-1.0.46 Driver corrections tracked through #761/#763: secure Remember Me, optional biometric sign-in, delivery Today/All/From-To filters, and the unified accepted-delivery Receive/Failed execution path for both B2B and B2C.
- Publish Customer Commerce V4 (#764, lanes #765-#773): one platform Customer identity across Retail and Wholesale, Dashboard-authoritative Retail banners with 5-second direct exact-store entry, store-scoped carts/order routing, unified login/register with Remember Me/biometric resume, complete Retail and Wholesale journeys, converged Dashboard order intake, and App Preview parity.
- Keep Retail/Wholesale as commerce context rather than separate authentication personas; preserve exact store/channel authorization, tenant isolation, self-store purchase protection, pricing/MOQ authority and driver/notification routing across the synchronized release.
- Ship the corrected Dashboard Customer App Preview runtime/inspector so authenticated preview uses the unified platform Customer identity while exposing auth mode separately from exact commerce context, with Draft/Published isolation and shared Flutter runtime parity.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.47 / mobile build 1.0.47+47 because published 1.0.46 is immutable; preserve production minimum-version, force-update, Driver location-enforcement and Assistant activation settings unchanged.

## 1.0.46 - 2026-10-02

- Publish the completed unified Wholesale/Retail commerce-isolation program (#734): authoritative platform identity and Retail Store ownership, backend self-store purchase protection, platform-first Customer entry with exact Retail placement/store routing, and strict B2B/B2C order ownership.
- Ship context-safe Customer address books and Wholesale order history/timeline, with Retail/Wholesale address, order and checkout data isolated by authoritative channel/store identity.
- Enforce Wholesale/Retail Driver tenant isolation and converge the full Driver App shell and Assignment Details lifecycle actions while preserving proof, failure and status-transition requirements.
- Isolate operational notification audiences and deep links by customer/driver/store/channel context, and auto-launch Dashboard App Preview into published Customer guest or the first eligible Driver context.
- Include final Customer + Driver E2E convergence and release guards; skip reserved 1.0.45 and synchronize Dashboard, Customer and Driver release identities at 1.0.46 / mobile build 1.0.46+46 without changing production force-update, minimum-version, Driver location-enforcement or Assistant activation settings.

## 1.0.44 - 2026-10-01

- Publish the completed Customer Journey V2 (#675) as NEW-only production runtime: store-scoped Retail catalog/cart/checkout, guest-to-auth cart continuity, account surfaces, authoritative orders/tracking, operational notifications, integrated guest E2E acceptance, legacy B2C purge and anti-regression guard.
- Publish the completed Driver Journey V2 (#686) as NEW-only production runtime: authoritative active-assignment flow, start/delivered/failed completion sheets, notes/proof handling, Dashboard delivery evidence, lifecycle notifications, assignment-to-proof E2E acceptance, legacy Driver journey purge and anti-regression guard.
- Keep Customer and Driver commerce/delivery context authoritative across navigation, deep links, notifications and release preview gates, with the converged shared APP-PREVIEW and Platform Customer Commerce CI coverage.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.44 / mobile build 1.0.44+44 without changing production force-update, minimum-version, Driver location-enforcement or Assistant activation settings.

## 1.0.43 - 2026-10-01

- Republish the Dashboard update under a new immutable version so installed 1.0.42 systems receive the shared-host public-runtime repair from #670/#671.
- Ship canonical `backend/public/**` files and safe shared-host aliases for `/assets/**`, `/brand/**`, `/demo/**`, and `/preview/**` in the same Dashboard update package.
- Repair public static-file and directory permissions during update extraction so Leaflet, Driver live tracking, FOODEX branding, and Customer/Driver App Preview runtimes resolve from the production document root.
- Preserve production AppVersion minimum/force-update rows, Driver fresh-location enforcement state, and Assistant disabled/read-only defaults.

## 1.0.42 - 2026-10-01

- Publish the complete MOBILE-PROD-UX package: production static asset reliability, authoritative Driver delivery lifecycle and notifications, Driver Home/Deliveries redesign, persistent Customer sessions, Dashboard-managed retail banners, and the redesigned Customer marketplace home.
- Include the final integrated mobile acceptance lane with backend, Customer/Driver Flutter and iOS validation, runtime screenshot evidence, and APP-PREVIEW visual/pixel-parity gates.
- Ship the self-contained Customer and Driver Flutter Web preview runtimes inside the Dashboard update package so App Preview updates do not require a separate runtime deployment path.
- Integrate FOOD Assistant V1 as a deterministic Arabic/English conversational copilot with authoritative FOODEX business/operations data, no LLM or external AI API, disabled by default and read-only by default.
- Preserve production AppVersion minimum/force-update rows and Driver fresh-location enforcement activation state; this release does not activate those policies.

## 1.0.41 - 2026-10-01

- Publish all reliability fixes merged after the already-distributed 1.0.40 under a new immutable release identity; 1.0.40 is not reused.
- Ship production distribution support for the real Customer and Driver Flutter Web preview runtimes with versioned artifacts and deployment validation.
- Add Driver version-policy failure observability with safe timeout/network/HTTP/parse/policy classifications, retry context and correlation IDs.
- Make protected Customer B2B authentication return channel-aware while preserving exact safe internal destination/query semantics.
- Harden Customer runtime/product error UX with retry/back recovery, safe support references and sanitized diagnostics instead of raw internal route text.
- Harden Dashboard live Driver map initialization/feed failure handling, empty-feed timestamps and local Leaflet asset completeness.
- Unify Mobile Settings with authoritative AppVersion/location-rollout readiness and surface exact safe blockers without changing production enforcement.
- Preserve existing production AppVersion minimums, force-update state and Driver fresh-location enforcement activation state.

## 1.0.40 - 2026-10-01

- Synchronize Dashboard, Customer and Driver release identities at 1.0.40 / mobile build 1.0.40+40.
- Fix the Dashboard Coupons page HTTP 500 when optional coupon start/end timestamps are null.
- Safely redirect malformed/non-numeric Customer 360 placeholder references to the Customer 360 index while preserving the valid numeric scoped route.
- Add regression coverage for both System Inspector production incidents.
- Preserve production Driver minimum-version and fresh-location enforcement settings unchanged.


## 1.0.39 - 2026-09-30

- Synchronize Dashboard, Customer and Driver release identities at 1.0.39 / mobile build 1.0.39+39 after the completed Driver tracking rollout chain.
- Ship the real Customer Flutter Web preview runtime and authenticated/guest read bridge, including Draft/Published revision resolution, scoped live invalidation, parity coverage and sanitized preview diagnostics.
- Ship the revisioned storefront Draft/Published backend foundation and preview configuration contracts required by the real application preview runtime; editor activation remains governed by its separate task.
- Add Dashboard live Driver tracking, including the dedicated page, sidebar access and compact B2B/B2C dashboard map cards using locally served map assets.
- Add Driver minimum-version policy enforcement in the app runtime while preserving the existing production minimum-version setting; this release does not mutate production AppVersion rows.
- Add server-side fresh Driver location enforcement capability with audited enable/disable controls and a stable recovery contract, while keeping enforcement OFF unless activated separately through the governed production path.
- Close the unaudited environment-variable enable bypass so absence of the persisted environment-scoped enforcement setting resolves OFF.
- Add active-delivery Driver background location lifecycle for Android/iOS, with foreground-service/native declarations, logout and permission revocation shutdown, and no broad Android background-location permission.
- Add the real Driver Flutter Web preview bridge and dedicated preview Web validation without enabling native/background tracking in preview.
- Preserve rollout separation: publishing 1.0.39 does not activate Driver location enforcement or change the production minimum-supported version; heartbeat evidence and production activation remain separate operational gates.


## 1.0.38 - 2026-09-30

- Complete the dashboard-only recovery path for installations that received 1.0.36 files while database migrations remained pending: install the migration-free 1.0.37 bootstrap first, then the cumulative 1.0.38 update so migration auto-detection triggers backup + pending migrations without a manual checkbox or shell command.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.38 / mobile build 1.0.38+38.
- Fix production invoice PDF downloads when an existing Dashboard installation does not have the newly declared TCPDF Composer dependency in its vendor directory.
- Ship a pinned self-contained TCPDF 6.11.3 runtime and DejaVu font assets inside the Dashboard Update package, outside the protected Composer vendor tree.
- Prefer normal Composer autoload when TCPDF is installed and fall back to the bundled runtime automatically, requiring no SSH, Composer command or manual server-side action.

## 1.0.37 - 2026-09-30

- Recover dashboard installations where the 1.0.36 migration flag was omitted by shipping a new cumulative migration-bearing update.
- Auto-detect database migration files inside uploaded dashboard update ZIP packages so future updates do not depend on a manual checkbox.
- Remove the manual migration checkbox from the updated System Update interface while retaining backward-compatible request handling.

## 1.0.36 - Platform customer addresses, delivery location and lifecycle notifications

- Unify each Platform Customer into one cross-channel address book shared by Wholesale and Retail journeys, with manual entry, explicit foreground location sharing, corrected map pins, default-address management and strict ownership isolation.
- Validate checkout address ownership on the backend and persist an immutable delivery-address snapshot on the order so later address-book edits cannot rewrite historical delivery data.
- Add full Customer 360 address CRUD plus exact delivery-address and map actions in Order Operations without leaking coordinates into unrelated list views, logs or public APIs.
- Carry the immutable delivery coordinates into Driver assignments and expose a safe one-tap navigation action while preserving B2B/B2C assignment boundaries.
- Make order/assignment lifecycle notifications backend-authoritative for Customer and Driver, with safe deep-link payloads, durable delivery logs, deduplication, retry/backoff and invalid-token revocation.
- Harden mobile push lifecycle with explicit logout revocation, foreground/local-notification tap routing, cold-launch recovery and a stable per-installation identity that reconciles FCM token refresh without revoking another device.
- Add OpenAPI contracts, cross-surface PCX-09 E2E acceptance, Arabic/English mobile screenshot evidence and Admin runtime visual evidence for the address/location journey.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.36.

## 1.0.35 - Order operations detail stability

- Fix HTTP 500 when opening Order Management details for orders with driver assignment history.
- Cast driver assignment `assigned_at` and `completed_at` timestamps to datetimes before timezone formatting.
- Add regression coverage for an order detail containing completed driver assignment timestamps.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.35.

## 1.0.34 - Engagement, live advertising, operations and platform branding

- Add image-capable notification testing and promotional campaign pushes with FOODEX brand fallback imagery.
- Register Customer App push devices before login and keep anonymous installs eligible after logout.
- Surface foreground and data-only/silent Customer pushes as visible local notifications.
- Add store-scoped Live Ads administration with scheduling, duration, frequency, CTA, image upload and Customer App popup rendering.
- Add Operations > Order Management with platform/store-safe filtering, exact status and driver visibility, driver reminder pushes, status changes and driver assignment actions.
- Extend Retail and Wholesale storefront composition with configurable departments alongside categories, brands and existing managed banner/section layouts.
- Apply the FOODEX Economical Group logo to dashboard, Customer App and Driver App branding surfaces.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.34.

## 1.0.33 - Explicit platform customer marketplace identity

- Integrate the remaining #402 public platform marketplace work on top of the newer 1.0.32 wholesale-first Customer experience.
- Add an explicit `platform_customers` identity table while preserving the existing platform-user compatibility flag.
- Backfill platform identities for existing 1.0.32 registered customers during upgrade.
- Add public platform storefront and product endpoints with configurable principal Wholesale store resolution.
- Materialize B2B/B2C customer projections from one platform identity and keep store/channel order routing isolated.
- Allow registered platform customers to fall back to safe base Wholesale pricing when no explicit tier rule exists.
- Keep Dashboard, Customer and Driver release identities synchronized at 1.0.33.

## 1.0.32 - Wholesale-first Customer marketplace and platform registration

- Open the Customer app into the platform main Wholesale storefront after splash without requiring sign-in.
- Show Retail stores as a compact horizontal banner strip capped at roughly 20% of the viewport; tapping a banner opens that Retail storefront.
- Add public guest-safe marketplace and Wholesale storefront browsing APIs.
- Add Customer self-registration with one platform account and an active STANDARD Wholesale account.
- Materialize Retail customer records lazily per selected Retail store while preserving tenant isolation.
- Route carts and orders by the actual selected store/channel, not by a separate app registration.
- Keep checkout, order history and profile actions authenticated while allowing guest browsing.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.32.

## 1.0.31 - Open-ended B2B sales dashboard range

- Rename the wholesale dashboard chart from Daily sales / المبيعات اليومية to Sales / المبيعات.
- Remove the 31-day B2B dashboard date-range ceiling.
- Make From and To optional: no dates shows all available wholesale sales, From-only runs through today, To-only includes all available sales up to that date, and both dates apply the exact range.
- Keep the query restricted to the authoritative B2B principal scope and add regression coverage.
- Keep Dashboard, Customer and Driver release identities synchronized at 1.0.31.

## 1.0.30 - Retail store accordion and lookup creation UX

- Show existing Retail stores as a single-open accordion ordered from newest to oldest, with the latest-created store expanded by default.
- Keep store editing, Wholesale tier, support inspection and manager/role controls inside each accordion panel.
- Replace the generic inline Lookup create block with a clear type-specific Add button and responsive modal dialog.
- Use "Add new brand / إضافة علامة تجارية جديدة" for Brands and "Add new unit / إضافة وحدة قياس جديدة" for Units.
- Preserve tenant scope, explicit support access, image validation, RTL/LTR behavior and existing lookup authorization.
- Keep Dashboard, Customer and Driver release identities synchronized at 1.0.30.

## 1.0.29 - Advertising center, coupons and mobile trial login

- Add the Advertising / الدعايا admin section with promotional campaigns and tenant-scoped coupon management.
- Add complete Wholesale vs Retail coupon isolation, redemption rules, audit history and checkout application.
- Add per-retail-store feature switches for advertising campaigns and coupons.
- Add temporary username-only mobile trial login for Customer and Driver apps behind the dedicated feature flag, plus logout.
- Show synchronized 1.0.29 version identity in Dashboard, Customer and Driver so the main trial-distribution workflow can publish the deployable changes.

## 1.0.28 - Version-locked dashboard APK downloads and admin account/header cleanup

- Add a dedicated Applications section to the admin sidebar with direct Customer and Driver Android APK download links.
- Resolve each APK from the installed FOODEX dashboard version so version X can only download assets published under release tag vX.
- Publish Customer APK, Driver APK and BUILD_INFO.json as versioned GitHub Release assets from the trial distribution workflow.
- Synchronize Dashboard, Customer app and Driver app version identity at 1.0.28.
- Include the Firebase HTTP v1 notification object-serialization fix already merged after 1.0.27.
- Move account settings, language switching and sign-out out of the sidebar footer into the avatar menu beside live notifications.

## 1.0.27 - Professional store selector and Driver active-assignment hardening

- Ship the professional Customer multi-store selector v3 with the exact جملة / التجزئة tabs, live store-selector API data, responsive RTL/LTR behavior, backend logos/artwork fallbacks, and explicit loading/error/empty/closed-store states.
- Keep Retail and Wholesale selection context isolated and route the selected store/receiving-store context into the existing tenant-safe mobile flows.
- Make the Driver app request `scope=active` explicitly instead of loading assignment history by default.
- Treat `reassigned` assignment rows as historical in both the Driver API active scope and the Driver UI active filter, alongside delivered, failed, cancelled and unassigned rows.
- Add backend, HTTP integration and widget regression coverage for active-assignment visibility.
- Preserve the Firebase service-account OAuth2 push flow and full dispatcher assign/unassign/reassign controls already present on main.


## 1.0.26 - Dynamic Wholesale storefront and synchronized mobile builds

- Add a dedicated B2B/Wholesale Storefront Builder to the Wholesale admin workspace.
- Manage Wholesale logo, branding, theme colors, hero content, home-section order/visibility and uploaded banners from the dashboard.
- Reuse the canonical storefront settings/sections/media model instead of creating a separate hard-coded Wholesale UI engine.
- Add an authenticated Wholesale storefront API protected by existing B2B entitlement and Retail-to-Wholesale account mapping.
- Make the customer mobile Wholesale Home load its branding, colors, hero and section composition dynamically from the backend.
- Keep B2B pricing, MOQ, stock and checkout server-authoritative.
- Publish synchronized Customer and Driver Android builds as 1.0.26.


## 1.0.25 - Storefront administration and dashboard reporting controls

- Add the Retail Storefront Design Builder for store-scoped branding, theme colors, logo upload, home-section ordering/visibility, service zones, storefront banners and live administration preview.
- Keep Storefront administration tenant-safe: Retail managers can mutate only their assigned store, while platform support requires explicit audited support context.
- Standardize dashboard filter/search actions on the canonical FOODEX green action style across administration.
- Replace the fixed/single-day dashboard period controls in B2B and B2C with explicit From/To date ranges, defaulting to the last 7 days and supporting up to 31 days.
- Make B2B/B2C KPIs, revenue/order charts, order distribution and recent orders honor the selected range; B2B top products now follow the same range.
- Compare KPI deltas against the immediately preceding period of equal length and adapt chart spacing/labels for wider ranges.
- Preserve RTL/LTR, responsive behavior, Retail store/support context and dashboard search context.


## 1.0.24 - Driver assignment production hardening

- Reconcile B2B and Retail driver execution permissions during production upgrades so existing driver roles can execute their assigned deliveries after an update.
- Treat cancelled driver assignments as historical rather than active work in both the Driver API and Driver app filters.
- Block transitions against cancelled or unassigned assignment rows while preserving assignment history.
- Keep the Firebase service-account authentication and complete assign/unassign/reassign controls introduced in 1.0.23.
- Add regression coverage for cancelled assignment visibility and production permission reconciliation.

## 1.0.23 - Firebase service-account push and complete driver order control

- Replace expiring manually-entered Firebase access tokens with encrypted Google/Firebase service-account JSON and automatic OAuth2 token generation/cache for Firebase HTTP v1, while retaining legacy access-token compatibility.
- Add a Firebase connection test in Mobile & Push Settings and expose the sanitized provider/OAuth failure reason in test sends and delivery logs.
- Show the current driver and assignment status directly in B2B and B2C order management.
- Allow dispatchers to assign, pull/unassign, and reassign orders between drivers while preserving prior assignment rows and audit history.
- Add a dedicated Drivers & Delivery assignment-management panel covering current and historical assignments.
- Reconcile legacy Driver/store and assignment/store ownership from the authoritative order so already-assigned orders become visible in the Driver app again.
- Treat pulled/unassigned deliveries as historical instead of active Driver tasks, with Arabic/English status copy and regression coverage.

## 1.0.22 - Driver reliability, clear admin errors, and password reset

- Admin mutation conflicts such as duplicate driver assignment, missing Wholesale pricing, and invalid business state now return to the same screen and appear in the FOODEX feedback popup instead of the generic 409 error page.
- Driver App launches its UI before Firebase/push initialization so messaging startup cannot block the application opening.
- Driver login now uses the FOODEX branded surface, Arabic/English layout, and a password visibility eye control; native Android builds continue to apply the approved FOODEX icon and splash assets.
- Drivers & Delivery now supports authorized B2B and B2C driver password resets with tenant/store enforcement, password confirmation, audit logging, and immediate revocation of existing driver API sessions.
- Driver mobile package version aligned to 1.0.22 and covered by Flutter/backend regression tests.


## 1.0.21 - Wholesale Principal & Retail Commercial Setup
- Make FOODEX itself the single Wholesale principal; remove user-managed Wholesale branch selectors and keep warehouses beneath the principal.
- Create and edit B2B orders from a selected source warehouse and reserve stock only from that warehouse.
- Make B2B drivers, catalog, pricing and settings inherit the main Wholesale principal without selecting a store.
- Require a logo and Wholesale price tier when provisioning a Retail store; synchronize that tier to the Retail store's linked B2B purchasing account.

## 1.0.20 - System Inspector Production Hotfix
- Automatically restore and verify `public/storage` during Dashboard Update execution so uploaded media remains publicly reachable after upgrades.
- Prevent invalid Retail-linked Wholesale account status mutations in the B2B UI and explain that the Retail store controls the lifecycle.
- Exclude active B2B accounts without an approved price tier from order creation, preventing the pricing 403 recorded by System Inspector.
- Keep the shared dashboard order form on Egyptian pound display and retain all 1.0.19 Retail production fixes.

## 1.0.19 - Retail Administration Production Fixes
- Fix direct Retail catalog access for platform support context and add a canonical category-management route so product/category administration no longer falls into the Wholesale tenant path.
- Complete Retail inventory management with warehouse creation, initial/current stock balance creation and stock adjustments from the Retail workspace.
- Add Retail customer image upload, replacement and removal with a neutral fallback avatar when no image is available.
- Replace free-form banner URLs with store-scoped product/category targets and accept valid banner images without the previous minimum-dimension rejection.
- Render Arabic PDF reports with Unicode RTL fonts instead of WinAnsi text to eliminate garbled Arabic exports.
- Reconcile production schema drift for category images plus customer-image and banner-target fields, with regression coverage for the reported production defects.

## 1.0.18 - Premium B2B Dashboard Reference Layout
- Rebuild the Wholesale dashboard to match the supplied premium reference proportions with four KPI cards, a seven-day sales chart, order distribution donut, latest orders, top-selling products, and operational alerts.
- Use live tenant-scoped B2B orders, customers, inventory, invoices and product sales instead of mock numbers.
- Add responsive RTL/LTR behavior while preserving the widescreen administration shell and B2B authorization boundaries.
- Add regression coverage for the reference 841x564 dashboard geometry and live business data.

## 1.0.17 - Widescreen Responsive Administration
- Make the shared FOODEX administration shell fluid on 1440p, 1920p and ultrawide displays instead of capping content at narrow fixed widths.
- Expand the Retail dashboard across the available viewport with wider desktop grids, larger analytics surfaces and consistent spacing.
- Standardize the desktop/sidebar/tablet geometry across Reports, Mobile Settings, Catalog and Lookup administration.
- Keep data tables inside self-scrolling containers so narrow screens do not force page-level horizontal scrolling.
- Align responsive behavior around common 1023px tablet, 767px mobile and 479px compact breakpoints while preserving RTL/LTR sidebar behavior and business logic.

## 1.0.16 - B2B Upgrade RBAC Reconciliation
- Reconcile canonical built-in FOODEX roles and permission assignments during upgrades so existing installations cannot retain stale B2B authorization state.
- Restore B2B_ADMIN and wholesale operational roles to their canonical global scope and expected permissions, including Orders, Catalog, Inventory, Drivers, Pricing, Finance, Reports and Settings access.
- Preserve custom/delegated roles unchanged and keep Retail store isolation and cross-channel restrictions intact.
- Add an upgrade regression test that starts from an intentionally stale B2B_ADMIN database and verifies every approved B2B administration module opens successfully.

## 1.0.15 - Admin Authorization Surface Audit
- Replace raw 403 responses on visible Retail store-scoped lookup actions with an actionable Explicit Support Access validation flow for platform owners.
- Keep Retail tenant isolation intact: SUPER_ADMIN must still explicitly enter audited support access before mutating store-owned Brands or Units.
- Add automated navigation authorization coverage for SUPER_ADMIN, B2B_ADMIN and B2C_STORE_ADMIN so every visible admin destination must open without 403/404/5xx responses.
- Preserve forbidden cross-domain and cross-store surfaces while making the visible administration experience internally consistent.

## 1.0.14 - Business Navigation, Self-Service Profile and Premium Mobile Commerce
- Reorder administration navigation around the actual business flow and make the sidebar compact/collapsed by default with a single authoritative permission-driven implementation.
- Add a self-service user profile showing global/store roles and effective permissions, plus secure password change and Arabic/English account language switching.
- Remove conflicting legacy sidebar and mixed-domain management UI; SUPER_ADMIN no longer receives Retail operational navigation outside explicit Retail support context.
- Replace banner image-path text entry with real verified image upload, preview, replacement and file cleanup inside the Retail content workspace.
- Seed three premium merchandising banners per demo Retail store with reusable visual assets.
- Upgrade Customer mobile visual system and Retail journey surfaces for a premium e-commerce presentation while preserving API, routing and checkout rules.
- Upgrade Driver mobile home, search/filter surfaces and delivery task cards for a professional field-operations experience.
- Keep Wholesale/Retail authorization, tenant boundaries and all existing business logic unchanged.

## 1.0.13 - Premium Administration and System Inspector
- Apply a shared premium form layer across administration with explicit field labels, descriptive placeholders, consistent controls, image previews and responsive spacing without changing business rules.
- Show clear Bootstrap-style success/error modals after admin mutations and validation failures while retaining server-authoritative validation.
- Add semantic icons to administration tabs and convert retail-store provisioning into a two-step tabbed flow for store details and manager assignment.
- Add the System Inspector to the administration sidebar to capture server, route, JavaScript and Fetch failures with correlation, user and store context.
- Add downloadable JSON diagnostics with runtime/storage health so deployment problems can be shared for troubleshooting.
- Harden catalog/category/brand image persistence by verifying bytes on the public disk, rolling back orphaned files on database failure and preserving safe image replacement/deletion.
- Make the installer guarantee the public storage link required by uploaded media, with an Inspector repair action for existing deployments.
- Preserve Wholesale/Retail tenant boundaries, permissions and the existing business model.

## 1.0.12 - Administration Navigation and Tenancy Audit
- Keep SUPER_ADMIN on the platform control plane by default and require explicit audited Retail store inspection before entering a Retail tenant workspace.
- Remove duplicate and legacy mixed-domain administration entry points that could conflict with the authoritative Wholesale/Retail business model.
- Make all B2B and Retail workspace navigation permission-aware so links are not shown when the current user cannot open the destination.
- Keep product/catalog actions in catalog management and inventory/warehouse actions in inventory management.
- Add persistent user logout to the shared administration sidebar.
- Complete Arabic administration copy cleanup so Arabic screens do not show English helper text where a proper Arabic label exists.
- Add descriptive placeholders to administration text fields and a shared fallback for newly introduced fields.
- Preserve the 1.0.11 Demo Data dashboard workflow and permission/audit safeguards.

## 1.0.11 - Demo Data Admin Access Fix
- Add a dedicated `/admin/security/demo-data` management page instead of relying on a command-line seeder workflow.
- Allow authorized `demo_data.manage` operators to create/rebuild and clear isolated FOODEX-DEMO records from the dashboard, including on the deployed environment.
- Keep demo-data mutations audit logged and cleanup restricted to FOODEX-DEMO markers and the `demo.foodex.test` namespace.
- Add the Demo Data page to administration navigation and provide Arabic/English UI copy.
- Keep dashboard update generation cumulative from FOODEX 1.0.6 so 1.0.6 through 1.0.10 installations can upgrade directly.

## 1.0.10 - Login-first Home Entry
- Redirect the public FOODEX root URL directly to the canonical Retail management login.
- Keep the installer endpoint and authenticated management workspaces unchanged.
- Add regression coverage that keeps the root login-first even when a browser already has an authenticated management session.
- Allow dashboard update bundles with no database migrations and report `contains_migrations=false` accurately for routing/UI-only patch releases.

## 1.0.9 - Retail Replenishment from Wholesale
- Represent every Retail store as a managed active Wholesale customer account, including safe backfill for existing stores and automatic linking for new stores and demo fixtures.
- Keep linked Wholesale account identity/status synchronized with the Retail store and prevent manual account-state drift.
- On a linked Retail customer's delivered B2B order, atomically consume Wholesale reservations and receive the exact ordered quantities into that Retail store only.
- Materialize tenant-owned Retail product/category/brand/unit records from the Wholesale source while preserving SKU, order-name snapshot, description, hierarchy, images and lookup metadata without sharing tenant-owned rows.
- Record purchase cost separately from Retail selling price, preserve an existing Retail selling price, and initialize a new Retail selling price from the received unit cost.
- Add idempotent replenishment and line-level provenance records so delivery retries cannot duplicate stock.
- Surface Retail stores clearly in the Wholesale customer picker and show purchase cost separately in Retail product management.
- Add end-to-end regression coverage for exact-store receiving, cross-store isolation, account provisioning and repeat-delivery idempotency.

## 1.0.8 - Wholesale / Retail Isolation Hardening
- Make global operational roles wholesale-only and introduce store-scoped Retail Operations, Inventory, Finance and Customer Support roles.
- Enforce Retail-only `user_store_roles` and reject attempts to assign store-scoped roles to wholesale stores.
- Make permission resolution channel-aware so a global wholesale permission can never authorize a Retail store and a Retail role can never authorize another store.
- Preserve existing Retail assignments through a migration while cleaning invalid cross-channel role assignments.
- Keep lookup visibility and mutations isolated to platform-global, wholesale, or the exact assigned Retail store.
- Remove user-facing B2C wording in favor of `التجزئة` / `Retail` while retaining internal `b2c` route/API/database identifiers for compatibility.
- Add regression coverage for wholesale-vs-Retail isolation, Retail-store-to-Retail-store isolation, RBAC assignment boundaries and visible terminology.

## 1.0.7 - Retail Admin Isolation and Catalog Reliability
- Complete the B2C retail-admin surface audit so store managers enter their own retail dashboard and remain scoped to assigned stores.
- Restrict platform store management to the platform owner while preserving tenant-scoped product, category, inventory, customer, promotion, content, driver and reporting workflows.
- Add role-scoped Brands & Units management with validated brand image upload and grid thumbnails.
- Fix catalog product editing for legacy references and tenant-owned dropdown data to prevent 422 failures.
- Harden promotional notification targeting so retail admins cannot address users from another store or mismatch customer/driver app audiences.
- Refresh the tester distribution from the current main source and build the dashboard update cumulatively from the previous VERSION boundary.

## 1.0.6 - Multi-Tenant Operations, Orders, Campaigns and Catalog Media
- Complete the Multi-Store / Multi-Tenant architecture with isolated B2B wholesale and B2C retail workspaces, tenant-owned catalogs, customer-domain separation, scoped lookups and retail-store provisioning.
- Add complete dashboard order management for B2B and B2C including manual creation, pending-order editing, pricing, discounts, delivery fees, payment records, inventory reservations, lifecycle transitions and driver assignment.
- Add complete driver order execution with assigned/active/completed/failed views, customer/address/item/payment details, delivery actions, failure reasons, retry flow and authoritative order-status synchronization.
- Add scheduled promotional notification campaigns for B2B and B2C with one-time and recurring schedules, pause/resume/cancel, tenant-scoped audiences, Firebase delivery, run history and duplicate-dispatch protection.
- Add product galleries and dedicated category images with upload/change/remove controls, primary image ordering, tenant-safe storage, API image URLs and Customer mobile rendering.
- Wire production Android Firebase client configuration for Customer and Driver package IDs.
- Preserve non-destructive upgrade compatibility from FOODEX 1.0.5; this update contains database migrations.

## 1.0.5 - Operational Admin CRUD
- Add tenant-scoped B2C order transition, driver assignment and inventory adjustment controls directly from operational workspaces.
- Seed safe default product units and B2B price tiers for fresh installations and upgrades.
- Preserve the newer catalog/business management centers while integrating the remaining RTL-admin CRUD work from PR #235.
- Add a central Operations & Data Management center for warehouses, stock, customers, promotions, banners and drivers.
- Add create/edit/delete/deactivate actions with server-side permission checks and dependency safety.
- Wire B2C Inventory, Customers, Promotions, Content and Drivers screens to their management actions.
- Keep inventory adjustments above reserved stock and record stock movements.

## 1.0.4 - Admin CRUD and Arabic RTL
- Fix the unified Arabic admin shell so the navigation sidebar renders on the physical right while English remains left-to-right.
- Add a server-authorized Catalog & Store Management center with product, category, brand, unit and store administration.
- Add product create/edit/delete/deactivate behavior, store assignment and pricing, and category create/edit/delete with dependency protection.
- Wire dashboard and B2C product actions to the management center and add regression coverage for fresh CRUD workflows.

## 1.0.3 - First-install Admin Access
- Redirect unauthenticated management requests to the admin login instead of a raw 401 page.
- Allow the installer-created `SUPER_ADMIN` to open B2C management surfaces before any stores exist.
- Add regression coverage that verifies the fresh-install Super Admin can open every management GET surface without permission errors.

## 1.0.2 - MySQL/MariaDB Production Installer
- Switch production and first-run database configuration to MySQL/MariaDB for cPanel hosting.
- Require `pdo_mysql`, default to port 3306, and retain PostgreSQL runtime compatibility for legacy installations.
- Add MySQL backup/restore support to the updater and validate deployment recovery against MySQL + Redis.
- Refresh the first-install setup bundle so `foodex.50sols.com` can install directly against `solscool_foodex`.

## 1.0.0 - Release Candidate
- Complete FOODEX Web administration for B2B and B2C with scoped data, permissions, reporting, governance, localization and audited actions.
- Complete Customer mobile B2B/B2C journeys for authentication, catalog, pricing, cart/checkout, orders, invoices, account data, profile and version policy.
- Complete Driver mobile B2B/B2C delivery journeys with task lifecycle, empty/offline handling and bilingual RTL/LTR presentation.
- Production-readiness foundations include installer/updater recovery, PostgreSQL/Redis acceptance, OpenAPI/security validation, observability and release-mode mobile validation artifacts.
- FOODEX brand system is applied across Web, Customer and Driver surfaces.
- Runtime evidence pack contains 110 real screenshots covering all 46 approved baseline screens plus Driver states in Arabic and English.
- Production deployment, signed mobile distribution and final physical-device acceptance remain gated by the external inputs tracked in #124 and #125.

## 0.1.0 - Bootstrap
- Monorepo and architecture foundation.
- Laravel/API/admin foundation.
- Customer and Driver Flutter foundations.
- PostgreSQL schema foundation.
- OpenAPI/installer/updater foundations.
- CI/tests/governance/design-reference indexing.

