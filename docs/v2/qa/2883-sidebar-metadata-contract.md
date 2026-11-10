# V2 #2883 / P359 — Canonical Sidebar Metadata Contract

**Integration:** `Foodex_V2` only. **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P359. **Parent:** #2503.

## Placement / source of truth

**Reuse** `AdminNavigation::groupsFor($user)` and the existing `AdminSidebarManager` registry/layout. There is no new screen, menu route, entitlement engine, database table, user input or authorizer. `App\Support\V2SidebarMetadata::forUser($user)` is an internal **read-only projection** of the already-authorized Sidebar. It must never be used as proof of route permission.

Group metadata: `group_key`, `label_ar`, `label_en`, and `items`.

Item/child metadata: `group_key`, `item_key`, `label_ar`, `label_en`, `permission` (only when exactly one), `permissions`, `permission_mode`, `scope`, `channel`, `module`, `readiness`, `route`, and `children`.

Labels are resolved from the existing localized Laravel catalogs and saved per-locale overrides; missing keys return null, never a silent opposite-language fallback. A required permission set with `mode=any` cannot be represented as a single privilege. `module` codes for existing ERP items are annotations of the canonical Enterprise ModuleRegistry names. They **do not enable a module** or bypass tenant/store/channel/permission checks.

`readiness=diagnostic_only` means the existing Navigation directs the user to module diagnostics, **not** the operational ERP route. `readiness=route_guarded` means a route candidate was selected. It does **not** mean that an ERP module has been certified ready, nor is it an HTTP-access authorization grant. Controllers, route middleware, RBAC, module entitlement, workflow readiness and maker/checker remain authoritative at request time.

## Safety / acceptance matrix

- **Roles + HTTP:** an unprivileged user receives neither privileged metadata nor access to the protected Sidebar-layout endpoint (403).
- **Company/store/channel:** the projection is restricted to the caller's canonical navigation; B2B account metadata does not invent B2C store entry points.
- **Role-visibility deny:** removes corresponding metadata without revoking or modifying the underlying role permission.
- **Arabic/English:** both labels resolve from authoritative `ar`/`en` catalogs; no hard-coded system labels in UI.
- **Module/entitlement/readiness:** annotation only; existing direct-route checks and module guards remain in force. The projection does not declare operational readiness.
- **Accessibility/RTL/responsive:** no renderer or screen change; existing Sidebar UI contract continues unchanged.

Dedicated regression: `backend/tests/Feature/V2SidebarMetadataTest.php`. Run focused test, Pint, static analysis and exact-head Required CI on the one PR before any merge. No tests are claimed passed until verified.

## Mobile impact assessment

| Surface | Classification | Evidence |
| --- | --- | --- |
| Laravel Backend | Affected internally | Additive typed read-only service; same authorization data |
| Dashboard | No user-facing behavior changed | Existing navigation renderer/route registration untouched |
| Customer | Unaffected | No API, mobile payload, auth, store/channel or deep link changes |
| Driver | Unaffected | No API, mobile payload, auth, store/channel or deep link changes |
| Van | Unaffected | No API, mobile payload, auth, store/channel or deep link changes |

Other V2 Issues own route readiness, Sidebar authorization, operational ERP deployment and runtime browser acceptance. This Issue does not close those scopes.

**Forbidden:** merge into `main`, production deploy, release, tag, or live-account mutation.
