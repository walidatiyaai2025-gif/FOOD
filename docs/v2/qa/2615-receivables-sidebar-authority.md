# V2 #2615 — P285 Receivables / الذمم Sidebar authority

## Source and placement
- Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P285; parent #2503.
- Domain/entity: Finance & Treasury; AR and AP financial documents.
- Actor: authorized Finance/AR/AP staff within an eligible B2B or B2C store and legal entity.
- Goal: discover the existing debtors/creditors workbench without a dead URL, an unready module, or a separate authorization policy.
- Reuse → Extend → Compose → New Screen: **Reuse** FinanceWorkbenchController::index, route admin.erp.finance.index and its existing receivables and payables page tabs. **Compose** two already-authorized canonical tab destinations under one P285 Sidebar business group. No new route, model, database migration, financial mutation or parallel renderer.
- Navigation: SIDEBAR, group receivables, children erp_receivables and erp_payables, each links to the original Finance Workbench with store_id + tab query params. Existing Finance group and its three business destinations remain unchanged. Existing server-side 403/404 and maker/checker rules remain authoritative.

## Exact authorization projection
- Both children require an active authenticated user with effective finance.view for an eligible active store.
- Finance module must be enabled, entitled to the store and operationally ready against the certified manifest. A ready flag alone is not a permission grant.
- Enterprise legal-entity capability finance.ar.read controls the Receivables link; finance.ap.read controls Payables. These are exactly the Finance Workbench's existing tab permission/entitlement checks.
- Candidate store choices mirror the Finance Workbench's channel-filtered, active, ordered and bounded context selector (100 rows). B2B candidates also pass the Wholesale role boundary. Fail closed on missing org scope, entitlement/route errors and explicit user permission DENY.
- Layout overrides cannot grant access or bypass a direct HTTP request check. Empty groups disappear; unauthorized links do not redirect to an unrelated page.

## Accessibility, localization, response and layout
- Group label: Arabic **الذمم**, English **Receivables & Payables**. Child labels reuse finance_workbench.tabs.receivables and .payables.
- Existing responsive/RTL-LTR Dashboard Sidebar and Finance tabs are the canonical renderer; no new touch targets or CSS. Test verifies both locale labels, route reachability and dir.
- Retains established empty/data/failure states and avoids exposing financial document IDs outside the canonical workbench.

## Customer / Driver / Van individual contract impacts
- **Customer**: no mobile code, REST endpoint/schema, account identity or order/statement contract changes.
- **Driver**: no mobile code, wallet/settlement REST contract or B2C dispatch changes.
- **Van**: no mobile code, collection/route/assignment REST contract or B2B Van-only dispatch changes.
- Change confined to authenticated Dashboard Sidebar navigation and backend feature regression. No separate mobile implementation issue warranted without an actual affected contract.

## Acceptance and CI
- Focused test: backend/tests/Feature/V2ReceivablesSidebarGroupAuthorityTest.php.
- Scenarios: bilingual authorized links + real HTTP 200, Finance disabled/uncertified fail-closed, AR-only legal entity entitlement hides AP + HTTP 403, foreign B2C/B2B direct access denied, explicit user DENY, inactive and roleless users.
- Gate: Repository Policy, FOODEX V2 Isolation, Backend Fast-Fail/Pint/PHPStan, localization, complete Laravel, MySQL/Redis fresh-install/upgrade/recovery and terminal Required CI on exact PR SHA. Record run IDs and release ownership only when observed.
- Integration strictly through one PR into Foodex_V2; main, production deployment and release are forbidden. Owner #2503 defers only final manual UAT; all automated checks remain required.
