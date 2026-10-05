# FOODEX 1.0.57 — Admin Grid Action Audit

Issue: #943  
Branch: `fix/943-v1.0.57-uiux-map-silent-refresh`

## Rule adopted

Every Dashboard table/grid that exposes an **Actions / إجراءات / الإجراءات** column is normalized to one compact vertical-ellipsis (⋮) trigger per row.

- Read-only row data remains visible in the grid.
- Row operations move behind the ⋮ trigger.
- Simple link/submit/delete actions render in a floating popover.
- Edit forms that contain fields open in a modal dialog from the action popover instead of expanding the table row.
- Existing routes, HTTP methods, CSRF fields, authorization and business rules are preserved.
- The enhancer is shared from `admin/_brand-components.blade.php`, so current and future admin tables using the standard action-column heading inherit the same behavior without duplicating screen-specific JavaScript.

## Action-grid surfaces found in the current Dashboard

The repository audit found standard action columns in:

- `admin/b2b-workspace.blade.php`
- `admin/b2c-workspace.blade.php`
- `admin/catalog-management.blade.php`
- `admin/lookup-management.blade.php`
- `admin/order-operations.blade.php`

The shared runtime also covers dynamically inserted rows through a `MutationObserver`.

## 1.0.57 related runtime fixes included on the same branch

- B2B Customer API calls now send `X-FOODEX-Customer-Domain: b2b`, preventing ambiguous `GET /api/v1/profile` HTTP 409 responses for identities that can access both Retail and Wholesale contexts.
- Admin login refreshes the active CSRF token immediately before credential POST, closing the stale-tab/session race that produced HTTP 419 CSRF token mismatch events.
- Dashboard live-map work remains on this same release branch, including silent refresh behavior from #943.
