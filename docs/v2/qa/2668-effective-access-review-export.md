# V2 #2668 / P338 — actual effective access review, safe global export

Source: FOODEX Unified RBAC/ERP/Sidebar 2026-11-09, original DOCX paragraph **P327**, registry key **P338**. Parent #2503. Integration target: **Foodex_V2 only**.

## Existing canonical placement

- Domain / entity: Administration → Security & Governance → effective user access / role-scope inventory.
- Actor: Super Admin for a platform-wide all-account report; delegated `security.view` personnel may review permitted Security Center governance information but cannot download the global identity/permission snapshot.
- User goal: inspect authorized global role-scope and effective permission evidence, with a recorded export event.
- Navigation type: **PAGE_TAB** in the existing Administration Hub → Security Center → Governance, then contextual CSV report action. No duplicate Sidebar entry or route.
- Placement: **Reuse** the existing `admin.security.index?section=governance` and `admin.security.reports.export` routes, with a narrower server-side authorization decision.
- Pattern: existing governance dashboard/report actions, not a new operational workbench.
- Desktop/tablet/mobile and AR/RTL vs EN/LTR: retain existing same responsive page and localized labels; hide only an unauthorized report link, never use visibility as the security boundary.
- Permission/source: `security.view` remains the existing governance entry gate, but **SUPER_ADMIN global role** is additionally required for the `effective-access` CSV because it enumerates *every account's* name, email, global/store assignments and effective permissions. Other reports and their existing authorization contract are untouched.

## Confirmed defect, fix, and threat model

The original `SecurityController::exportGovernanceReport` gated every report on `security.view` alone. A delegated security viewer with only that permission could download `effective-access` across all users, including unrelated channels/stores, without a specific global-export authorization. This violates least privilege and exposes personal and authorization metadata.

The controller now checks the authenticated actor's global `SUPER_ADMIN` role **before querying rows or writing the audit event**, returns HTTP 403 to delegates and roleless users, and continues to preserve CSV reporting/auditing for authorized Super Admins. The Governance section no longer presents the global effective-access export action to a non-Super-Admin actor, but the **HTTP guard is authoritative** even if the direct URL is known.

No new role, permission, tenant bypass, module activation, data mutation or installation/release action was introduced. A scoped/redacted delegated export, if required later, needs its own approved security contract and atomic Issue rather than silently permitting a platform-wide snapshot.

## Automated test coverage

`backend/tests/Feature/V2EffectiveAccessReviewExportAuthorityTest.php` checks:

1. Super Admin can discover the existing report in English LTR and Arabic RTL, download actual CSV containing effective role/permission rows, and produce a `security.governance_report.exported` audit event.
2. A delegated global `security.view` actor may enter Governance but neither sees the global-export link nor can fetch it directly (403) in either locale; unauthorized export does not create an export audit entry.
3. A roleless active identity receives 403 and no export audit.
4. All other existing report actions, route names and session/security gates remain unchanged.

The test deliberately uses synthetic seeded identities; no live credentials, user account export, private dataset or production screenshot is committed. It does **not** certify a complete account-by-account production access review or export authorization for a hypothetical scoped auditor.

## Cross-app impact assessment

- **Customer:** no customer route, permission payload, Flutter or token change; only a Dashboard Security Center global CSV action is further restricted.
- **Driver:** no dispatch, collection, Driver API, Flutter or notification contract changed; B2C DRIVER ONLY unchanged.
- **Van:** no routing, Van API, Flutter or warehouse assignment contract changed; B2B VAN ONLY unchanged.

## Acceptance and gates

Required before integration: independent exact-head Repository Policy, V2 Isolation Gate, Required CI and child Laravel backend tests, PHP syntax/Pint/PHPStan, Composer security, AR/EN, MySQL/Redis install/upgrade/recovery. Confirm branch+PR target `Foodex_V2` and latest HEAD SHA; no merge on pending/red. Final manual/browser/mobile viewport acceptance belongs to #2503/#2750 (**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL**) and is not invented here.

No `main` changes, production access, deployment, release, version bump, real-user data export, or new branch/PR for a previous issue.
