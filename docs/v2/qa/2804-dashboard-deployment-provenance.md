# V2 #2804 / P053 — Dashboard deployed version ↔ GitHub source evidence

Scope: read-only provenance on existing authenticated System Administration → System Inspector and its JSON export. PR base: Foodex_V2 only. Source: P053, parent #2503.

## Data authority and semantics

1. Installed database version: latest system_versions row by installed_at, id; same source as Update Center. Failure to read it means UNKNOWN.
2. Server source version: VERSION from the actual deployed directory.
3. Build version and source commit: declared Release/BUILD_INFO.json. SHA must be exactly 40 hexadecimal characters. Missing/invalid data means UNKNOWN.
4. Metadata state is CONSISTENT only if all three versions are present and identical AND a syntactically valid source commit is recorded. Otherwise MISMATCH for three known but unequal versions, or UNKNOWN for incomplete data.
5. Optional GitHub lookup: an authenticated platform admin explicitly requests verify_source=1; the server makes a bounded fixed-host GET to https://api.github.com/repos/walidatiyaai2025-gif/FOOD/commits/<sha> with 3-second timeout and no tokens. Exact SHA 200 = found; 404 = not_found; other results = unknown. Normal page and export never call GitHub.

A public GitHub commit existing is NOT an attestation that the active cPanel filesystem matches a signed release package. Stronger proof requires signed, exact-commit package provenance and authorized evidence from the deployed host. Foodex_V2 is intentionally isolated from production.

## Observed source limitation

In the inspected isolated source, VERSION = 1.0.127 while the checked-in Release/BUILD_INFO.json declares version = 1.0.95, source_commit = af349101a6a570ac7027f5d79753cb77d59858ed. These source files differ. This does not reveal the actual installed production version at foodex.50sols.com, which was not accessible by public web retrieval. No production deployment/change was performed.

## Canonical UI placement and security

- Domain: System Administration; entity: Deployment provenance; role: authenticated Super Admin; goal: diagnose installed Dashboard version and GitHub source parity.
- Workflow: infrequent read-only inspection; low data density. Parent: System Inspector at /admin/inspector; placement: EXTEND existing Inspector diagnostics. No new route, sidebar, screen or input fields.
- Navigation: ADMIN_SETTINGS → existing System Inspector; backend route authorization via existing platformAdmin; back to existing Admin Hub/Update Center.
- Desktop/tablet/mobile web: existing responsive inspector grid used, native focusable action; AR/EN labels through system_admin.provenance, RTL/LTR inherited. Missing, mismatch and GitHub outage states never appear green.
- Unauthorized, cross-role and cross-store actors: unchanged 403 existing Inspector protections including when verify_source=1.
- Existing diagnostic JSON export gets a safe deployment_provenance value without credentials, private file paths, API tokens or live customer data.

Mobile impact: Customer/Driver/Van — individual no-impact review. Customer has no API/identity/price/notification change. Driver B2C-only dispatch and Van B2B-only dispatch remain unchanged. No Flutter changes or mobile-issue creation needed for admin-only read-side analysis.

## Required test evidence and incomplete external attestation

Dedicated V2DashboardDeploymentProvenanceTest covers matching/mismatched/missing metadata, invalid JSON/SHA, GitHub exact SHA/404/503/mismatched SHA, no outbound calls for normal reads, AR/EN Inspector rendering, safe export, and unauthorized 403. Do not report source acceptance complete before exact-head CI.

P053 stays OPEN until a permitted authenticated inspection of the actual deployed Dashboard and its signed build/provenance confirms live filesystem version against the correct GitHub source. No main promotion or production action is authorized.
