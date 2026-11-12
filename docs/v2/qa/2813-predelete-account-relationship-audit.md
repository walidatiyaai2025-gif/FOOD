# V2 #2813 — P075 account classification and pre-deletion relationship safety

## Scope and authority

- Source: unified RBAC/ERP/Sidebar source P075 (native paragraph P068); parent #2503, integration `Foodex_V2` ONLY.
- Existing product placement: **Administration → Security & Users → Users**. Reuse `admin.security.index?section=users&assignment=roleless`; no parallel page or destructive action.
- Historical source says **seven accounts**; this is a dated export, **not** a live production count or authorized identity list. The automated seven-user fixture is synthetic. No real names, identifiers or production data were used.
- Users without global **and** store-level roles are read-only classified using `is_platform_customer`: platform customer (administrative role not required) or review-required. No automatic grant, reclassification, archive, deletion or mass update follows merely from rolelessness.
- Read/list is gated by canonical Security Center authorization; store-scoped users cannot view platform-level classifications or archive another identity. HTTP 403, not visual hiding, remains the security boundary.

## Executable regression

`backend/tests/Feature/V2PredeleteAccountRelationshipAuditTest.php` verifies:

1. Seven roleless synthetic accounts are shown in the authoritative paginated user list; AR/RTL classifications distinguish four customer and three review identities.
2. The review includes **existing active-session and personal-access-token relationships** on one account before the owner-approved soft-archive workflow. Archiving revokes its token/session but **retains the user row, identity/email, remaining six accounts, and an auditable event**; it never assumes rolelessness means safe hard deletion.
3. A B2C store operator cannot read the platform audit or archive an unrelated roleless account; HTTP 403, no status/audit mutation and the victim's token survives.
4. No Customer, Driver or Van API, Flutter app, route contract, or fulfillment decision changes. The three token types continue to rely on the existing centralized deactivation/revocation behavior; B2B=Van-only and B2C=Driver-only are unchanged.

## Remaining source-acceptance blockers — do not mark P075 completed on this test alone

This regression is **not a complete dependency/retention inventory**. It cannot establish whether historical accounts are currently present or prove each identity's supplier, ledger, order, legal-retention, open assignment, SSO or audit-reference relationships. The accounts must be reconciled through *authorized* current data, without copying any private records to GitHub. Before enabling irreversible deletion, implement/verify the comprehensive reference-impact preview and retention policy under #2809 (P071), protected identity guards under #2810 (P072), and confirmation/audit/restore workflow under #2811 (P073). No cascade or hard DELETE should be enabled from this PR, and its automated checks are not evidence of production data reconciliation.

The current #2811 PR #3211 owns `backend/app/Services/RbacManager.php`, `backend/resources/views/admin/security.blade.php`, `backend/lang/{ar,en}/admin.php`, and shared user lifecycle tests. This task deliberately does not edit those owned files. **P075 is open until the above functional dependency preview plus full source coverage are proved**, even if this isolated PR's exact-head CI is green and its bounded regression is merged.

## Validation and handoff

- Required: Laravel regression/full suite, PHP syntax/Pint/PHPStan, Security/OpenAPI, AR/EN, real MySQL/Redis install-upgrade-recovery, Repository Policy and V2 Isolation on the **same final head**; record their actual run IDs/conclusions before any merge.
- Manual browser/device/staging acceptance is deferred to V2 final per owner #2503, but executable dependency and authorization gates are not deferred.
- No main, production, release, tag, ZIP publication or automatic promotion is authorized.
