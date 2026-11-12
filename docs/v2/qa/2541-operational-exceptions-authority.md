# V2 #2541 — P211 Operational Exceptions route, authority and responsive access

## Authority and placement

- Source: unified RBAC/ERP/Sidebar discussion 2026-11-09, Sidebar line **P211**, parent #2503; PR target **Foodex_V2 only**.
- Domain: Orders & Fulfillment. User goal: review, triage and act on genuine order exceptions within the authorized store/channel, including Wholesale dispatch, Retail payments and invoice failures.
- Placement decision: **REUSE** existing Operations → Orders workbench; do not create a duplicate Sidebar destination, dead link or standalone exception screen.
- Canonical entry: authorized Sidebar item `order_operations` → route `admin.operations.orders.index`. In the existing page, `Operational exceptions / الاستثناءات التشغيلية` chart provides actionable links that drill into the same order grid with validated `exception` values. An accessible labelled exception picker is additionally reachable from the existing compact **More filters** details at all viewport widths.
- Navigation type: `PAGE_TAB` / `CONTEXT_ACTION` within existing `SIDEBAR` Operations destination. Existing Orders grid, detail, review/action permission boundaries, pagination, AR/EN strings, RTL/LTR direction and mobile-web shell are reused. No new route or menu group.

## Server-side authorization and data

- Controller: `OrderOperationsController@index`; `order_operations` navigation is a projection of `orders.view`, not an independent visibility rule. The request validates allowed `exception` values against `SalesOrderOperationsDashboardService::EXCEPTIONS`.
- Server evaluates channel + authorized store IDs through `availableOperationalChannels`, `operationalScopes` and `applyOperationalScopes`, then applies the selected exception to the **already scoped** order query.
- Supported exception values: `delayed`, `awaiting_dispatch`, `payment`, `invoice`, `cancelled_refunded`, `failed_returns`. Existing row actions still separately require their original manage/dispatch/finance permissions.
- Direct unauthorized HTTP access must return 403 or concealed 404, and untrusted exception query values must fail validation (422 JSON); no query value or Sidebar visibility can grant access to another store or channel.
- Read-only change to the filter's placement within the same form: mobile/tablet can reach the exception selector through labelled `details/summary`, without CSS `display:none` on the input. No status/dispatch/service/data mutation, new routes or permissions introduced.
- Readiness: this is the existing operational Orders capability, not a new or unready ERP module. When `orders.view` or valid store/channel scope is absent, both navigation and server access fail closed. An inactive/disabled underlying role or explicit DENY must not be treated as a feature readiness grant.

## Automated acceptance

`backend/tests/Feature/V2OperationalExceptionsSidebarAuthorityTest.php` covers:

1. Authorized Operations → Orders navigation plus real chart, exception control and localized AR/RTL and EN/LTR HTTP view; delayed exception drilldown reads only its intended row.
2. Six valid exception filters using real orders/payment/dispatch fixtures; both successful and terminal dispatch states; no foreign-store leakage; invalid filter returns HTTP 422.
3. B2C store operator permitted for its own store, denied a foreign store and Wholesale channel, with original rows left intact.
4. Roleless and explicitly store-denied actors rejected at the protected HTTP route; independent Sidebar visibility does not grant permission.
5. Responsive structural assertion: exception select occurs **once**, inside the accessible More filters popover and is never hidden by the former CSS media query.
6. Existing backend Laravel suite, lint/Pint/PHPStan, security/OpenAPI, AR/EN and MySQL/Redis install/upgrade/recovery plus exact-head Repository Policy and V2 Isolation are required. Test-only confidence does not certify a real device/browser UAT; manual UAT is deferred to the V2 final gate per latest owner #2503.

## Cross-app impact matrix

- **Customer:** no Flutter files or Customer API/notification contracts changed. Order status and invoice behavior remain backend-owned; existing V2 Customer issues retain those acceptance tasks.
- **Driver:** no Flutter/API change. Retail/B2C dispatch remains DRIVER ONLY. No driver assignment, wallet, proof or notification modification.
- **Van:** no Flutter/API change. Wholesale/B2B remains VAN ONLY, with `awaiting_dispatch` used as an exception when dispatch cannot assign. No fallback to Driver, no territory/visit change.
- Backend change is only existing admin Blade filter composition plus HTTP regression; full mobile builds should remain skipped under changed-area CI policy.

## Role, layout and failure check

- Role/action: `orders.view` for read, independent `orders.manage` and dispatch checks remain on mutation. Store/channel scope resolved by `OperationalTenantScope`, not caller-provided IDs.
- UI: desktop single-row compact controls; smaller widths use the existing More filters details for exception selection. Orders grid remains server-paginated and retains existing status/empty/data/row-action patterns. Keyboard summary, labelled select and responsive popover reused.
- Zero authorized children: existing `AdminNavigation::group` removes an empty group; no P211-only menu is created.
- No secrets or user-specific production data; all test orders/users are synthetic.
- **Acceptance policy:** merge only into `Foodex_V2` after terminal required checks succeed on exact final commit; then close #2541 only if the source-scope review confirms no remaining functional gaps. Neither merge nor CI represents production deployment or owner manual UAT.
