# V2 #2860 / P137 — Non-destructive legacy Sidebar migration and historical settings

- Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, paragraph P137; parent #2503.
- Owner-locked integration target: `Foodex_V2` **only**. No `main` change, release, cPanel change, or production account/data migration.
- Implementation: `backend/app/Services/AdminSidebarManager.php` and `backend/tests/Feature/V2SidebarLegacySettingsPreservationTest.php`.
- Placement: **Reuse** the existing `AdminSidebarManager` persistence layer and `admin.sidebar-layout.index` / `admin.sidebar-layout.update` under Administration > Sidebar Layout. No new screen, route, Sidebar item, permission, or UI primitive.

## Migration boundary and explicit owner decision

This issue can ship a safe non-destructive **technical compatibility foundation** without authorizing irreversible migration of old live settings. Final business acceptance of any legacy-to-V2 Sidebar mapping or purge is a separate owner/human decision requiring a mapping inventory, read-only baseline export and independent staging proof. The present PR does not delete old rows or install/trigger migration jobs in production.

Effective authorization continues to be decided by the authenticated route's gates and authoritative `AdminNavigation` before Sidebar preferences are projected. Role visibility is presentation-only: retained historical settings **never grant a denied permission, company/store/tenant/channel scope, or disabled module entitlement**.

## Data preservation contract

1. A save/reconciliation of a role's visible items no longer deletes historic `admin_sidebar_role_visibility` rows when the role no longer qualifies for an item. Disallowed items are always absent from the canonical authorized input, regardless of a retained preference.
2. `saveLayout` distinguishes an **omitted** registry key from an explicitly supplied key. Missing group/item values stay unchanged; an incoming partial migration cannot reset existing labels, order, location, enabled state or history by accident. Explicit edits from the existing fully validated Admin form retain current semantics.
3. On inserts, `created_at` is recorded; later updates change `updated_at` but keep original `created_at`. Old configuration identity and provenance do not disappear on edit.
4. Unknown/retired registry keys already stored in historical tables remain untouched; no schema change, destructive delete or backfill is introduced.
5. Existing `SidebarManagementController` authorization (`roles.manage`), validator, rollback-safe `DB::transaction`, audit-log before/after, and strict group/item key validation remain unchanged.

## HTTP / RBAC / channel and locale acceptance

- `admin.sidebar-layout.index` / `admin.sidebar-layout.update` require `roles.manage`; unauthorized active actors receive HTTP 403 in Arabic and English, with no mutation.
- Existing `AdminSidebarManagementTest` and V2 Sidebar permission/tenant tests remain mandatory regression gates for canonical allow/deny, multiple roles, B2B/B2C, cross-store and direct route access. A saved visibility setting never changes a permission row.
- The added test exercises partial group/item saves, retained historical disabled values, retained timestamps, a now-ineligible role's old visible preference, and AR/EN HTTP denial.
- AR/EN label values are retained byte-for-byte unless explicitly edited; no UI strings were added. RTL/LTR or responsive/keyboard HTML is not modified; the existing V2 browser/a11y acceptance gate remains applicable.

## Customer / Driver / Van impact matrix

| App | Classification | Evidence / rationale |
| --- | --- | --- |
| Customer | Not affected | No Flutter, API payload, identity, order, checkout or mobile navigation route changed. Role Sidebar preferences affect only the authorized Web Admin projection. |
| Driver | Not affected | No delivery dispatch, driver token, route/permission, API, notification or Flutter change. B2C→Driver invariant unchanged. |
| Van | Not affected | No wholesale dispatch, Van state/API/role contract or Flutter change. B2B→Van-only invariant unchanged. |

No distinct mobile issue is needed for this **Web Admin settings persistence-only** patch. If a later migration changes any API/mobile contract, open an app-specific V2 issue before that change.

## Verification and rollback

- Regression command (from `backend/`): `php artisan test --filter=V2SidebarLegacySettingsPreservationTest`.
- CI must pass latest exact-head repository policy, isolation, backend PHP/Pint/PHPStan/features, security and install/upgrade MySQL checks before isolated merge; document actual run IDs in PR.
- The change is **code-only**, no new database migration. If rolling back, older code would again delete ineligible preference rows and reset partial saves; do not run old code's writes until rollback assessment. Preserve database backup and pre-migration snapshot.
- Production historical rows and owner migration approval are **not inspected or claimed**; keep #2860 OPEN until business migration map and isolated staging acceptance are independently evidenced.
