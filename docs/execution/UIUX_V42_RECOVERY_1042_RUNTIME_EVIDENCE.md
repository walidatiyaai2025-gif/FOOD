# UIUX-V42-RECOVERY #1042 — Integrated Runtime Visual / Interaction Evidence

Gate Issue: **#1042**  
Integration target: `release/1034-uiux-v42-recovery`  
Integrated recovery merge HEAD reviewed: `1049663772d3106b6ce57c13470730a72241db0d`  
Runtime/build source HEAD: `28000baf73ecfb29a83ccecb49746b2040a651cc`

## Exact-source lineage

PR #1052 runtime evidence and Required CI were produced from `28000baf73ecfb29a83ccecb49746b2040a651cc`. The #1041 merge commit `1049663772d3106b6ce57c13470730a72241db0d` has that commit as a parent and a GitHub compare between the runtime source and the integrated merge reports **zero changed files**. Therefore the integrated product tree reviewed by this gate is file-identical to the runtime/build tree that produced the evidence below.

The #1042 branch adds only gate/evidence harness hardening plus this evidence record and matrix status updates; it does not introduce business-product behavior.

## Runtime evidence set

| Surface | Workflow / run | Job | Artifact | Result |
|---|---|---|---|---|
| Dashboard / Web runtime | FOODEX UI Visual QA / `37599427509` | `112719883897` | `11471224337` · `foodex-ui-visual-qa-1052` · sha256 `e12c26b954229bbec09a6546862c78cbdaaf819f9145256c30506413aeeda7e1` | PASS |
| Customer / Driver / Van runtime | FOODEX Mobile Screenshot Capture / `37599427493` | `112719883416` | `11471599809` · `foodex-mobile-screenshot-qa-1052` · sha256 `697854d3c839a0486393826012da3e511559d13d6ea8ba8c985efb90ab52d45c` | PASS |
| Field Operations runtime | FOODEX Field Operations Runtime Evidence / `37599427586` | `112719884225` | `11472405452` · `foodex-field-operations-runtime-1052` · sha256 `7bd86ed459b4e33142635190fd242b510dc943a759984ca832fdbb25bedcc41c` | PASS |
| Integrated tests / localization / builds | FOODEX Required CI Gate / `37599427933` | complete matrix | static audit artifact `11472321017` plus platform build artifacts | PASS |

The Web pack contains **138** real Laravel runtime PNGs, including **64** responsive-matrix captures. The Field Operations pack contains **30** authenticated Laravel runtime PNGs. The combined mobile pack contains production Customer, Driver and all **19 Van surfaces** in Arabic and English, with standard 430×932 portrait geometry and Van compact 360×800 evidence.

## Independent review method

This gate did not infer PASS from child-Issue closure. It reviewed the integrated runtime artifacts and the deterministic production-runtime assertions that generated them:

- Web capture runs the real Laravel application, authenticates real admin sessions, fails on auth redirects, checks the shared FOODEX shell, rejects routine raw JSON/numeric-ID controls, opens real row-action menus, verifies Administration is a single Hub entry, verifies Customer/Driver/Van application parity, verifies mixed Driver+Van live tracking, and rejects horizontal overflow at required widths.
- Field Operations capture runs the real Laravel routes in AR and EN at 1280/768/390 widths, requires authoritative lookups and map controls, verifies Advanced GeoJSON is collapsed, and rejects overflow. #1042 additionally exercises Territory add/drag/delete/Undo/Clear behavior through the actual Leaflet runtime.
- Commercial #1042 interaction evidence exercises the actual Sales Control Selling Unit / Availability / Targeting builders, break-pack Selling Unit lookup synchronization, Flash audience lookups, Product Builder and Selling Unit resolution without using raw JSON/internal-ID authoring.
- Mobile screenshot tests render the production app widget trees, not standalone mockup routes. Geometry is verified and the Customer/Driver/Van production navigation and identity remain authoritative.
- Direct evidence review covered the critical bilingual Login, Dashboard/Admin Hub, Sales Control, Flash Offers, Field Operations map, Customer order tracking/invoice, Driver delivery/notification, and Van route/commerce/finance surfaces. No mixed-language system UI, horizontal overflow, hidden primary action, or unresolved overlap was observed in the accepted evidence set.

## Requirement-by-requirement runtime acceptance

| Requirement IDs | Runtime / interaction evidence | #1042 result |
|---|---|---|
| D01, D02, D04, D06, D08, D09, D10, D11 | Web AR/EN runtime pack; desktop/1024/tablet/mobile responsive captures; shared-shell/raw-input/Administration-app-parity/mixed-live-tracking assertions | PASS |
| D03, D05 | Real notification/campaign/order record-action routes; explicit Edit affordances; ellipsis menus are opened by Playwright on desktop and mobile with overflow checks | PASS |
| C01–C06 | Sales Control + Flash Offers AR/EN responsive evidence; CommercialDashboardContractTest; #1042 builder/lookup interaction probe for structured units, availability, rules, break-pack, audience and product/unit authoring | PASS |
| F01–F04, F06 | 30-image Field Operations AR/EN responsive pack; real authenticated selectors for Van, representative, warehouse, Customer, Store, Route, Order and Territory lookups; compact action assertions | PASS |
| F05 | Real Territory map in AR/EN; map/Undo/Clear/Advanced controls; Advanced collapsed by default; #1042 browser probe performs point add, marker drag, marker delete, Undo, Clear and verifies canonical Polygon serialization/validation | PASS |
| MC01–MC05 | Customer production runtime screenshot matrix; compact/no-wrap/ellipsis evidence; polling/resume/stale/offline production-widget tests; approved production route/footer model retained | PASS |
| AC01, AC02 | Visible `Customer App / تطبيق العميل` screenshots and RTL/LTR widget runtime; Remember Me + secure biometric persistence/unlock tests | PASS |
| IC01 | Production B2B invoice detail evidence with FOODEX/company identity, authoritative lines/payments/totals plus invoice reconciliation tests | PASS |
| MD01–MD03 | Driver AR/EN production runtime evidence; compact/no-wrap/filter/ellipsis widget tests; foreground polling, resume refresh and stale/offline tests | PASS |
| AD01, AD02 | Visible `Driver App / تطبيق السائق` runtime evidence; Remember Me and biometric saved-session tests | PASS |
| LD01–LD03 | Foreground authoritative new-order alert runtime capture with View action; exact-assignment/order deep-link tests; revoked-payload protection; dedupe and already-open refresh tests | PASS |
| MV01–MV03 | Locked 19-surface Van inventory rendered in AR/EN, plus compact 360×800 evidence; route/map/visit/catalog/order/finance tests; resume/stale/offline tests | PASS |
| AV01, AV02 | Visible `Van App / تطبيق الفان` evidence; Remember Me, biometric-unlock, fallback and logout-clearing tests | PASS |
| AV03 | 19-surface capability inventory plus Dashboard Van administration, structured Mobile Settings, live tracking and finance-support runtime parity evidence | PASS |
| Q01 | Whole-tree static localization gate + successful Arabic/English localization job + reviewed AR/RTL and EN/LTR runtime evidence | PASS |
| Q02, Q03 | #1041 whole-tree static audit artifact and legacy route/screen inventories passed on the exact runtime source | PASS |
| V01, V02 | Combined Web, Field Operations and production mobile evidence above, with bilingual/responsive geometry and interaction review | PASS |

## Exact deterministic interaction/test proof

The exact runtime source also passed the Required CI suite. High-signal results include:

- Customer: 342 Flutter tests, including compact/no-wrap Orders, resume polling/stale behavior, visible Arabic Customer identity, secure Remember Me/biometric flows, authoritative B2B timeline and invoice reconciliation.
- Driver: 128 Flutter tests, including one-line filters, no-wrap identifiers, exact assignment push/deep-link resolution, revoked-access protection, dedupe/already-open refresh, visible bilingual Driver identity and biometric persistence.
- Van: 34 non-screenshot fast-fail tests plus 44 screenshot tests, including the locked 19-surface inventory, bilingual Van identity, authoritative route/map/visit/commerce/finance flows, Remember Me/biometric behavior and resume/stale preservation.
- Backend: 746 tests / 11,965 assertions, including Commercial Dashboard, Dashboard UI compliance, Field Operations UI foundation, Van control-plane parity and Van finance support.

Headless CI cannot display a physical OS biometric prompt or deliver an external Firebase packet from a vendor service. The accepted interaction proof uses the production auth/push application paths with deterministic platform/transport adapters, while the rendered production login and foreground alert surfaces are independently captured. No mock-only product route is substituted.

## Review decision

**PASS.** Requirements D01–D11 (defined rows), C01–C06, F01–F06, Customer, Driver, Van, Q01–Q03 and V01–V02 now have the required source/test/runtime evidence and are accepted on the integrated recovery lineage. There are no remaining #1042 FAIL/PARTIAL/UNKNOWN/UNOWNED rows.

G01/G02 remain owned by #1043 final convergence. R01 remains owned by #1021 exact-head release.
