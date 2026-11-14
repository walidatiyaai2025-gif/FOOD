# FOODEX V2 #3561 — portable literal Address Quality search

## Authority and scope
- Source lineage: Field Operations P266/#2596 and page action scope P082/#2818; final V2 acceptance parent #2503.
- Backend route: authenticated GET `/api/v1/admin/field-operations/address-quality`, existing `customers.view` Gate. No new route, Sidebar item, permission or user input field.
- Existing actor: authorized platform/Dashboard operations role. Unassigned role receives 403. No schema, finance, dispatch or audit change.
- Integration: issue-scoped PR **only** to `Foodex_V2`. Never main, version/tag, release APK, cPanel or production.

## Source defect and repair
The index endpoint originally escaped literal percent and underscore characters using backslash before applying Laravel LIKE. SQLite has no default backslash escape for LIKE, while MySQL/MariaDB backslash behavior depends on SQL mode, making user-supplied literal search unstable across supported databases.

The endpoint now escapes `!`, `%`, and `_` with an explicit `!` marker; all three fixed columns use bound `LIKE ? ESCAPE '!'` comparisons, with intentionally unescaped surrounding percent signs for substring matches. This is a read-accuracy/portability defect, **not** evidence of cross-store unauthorized access. The status/quality/subject filters, pagination and JSON structure remain unchanged.

## Acceptance evidence
- `backend/tests/Feature/V2AddressQualityLiteralSearchTest.php`: Laravel Sanctum-authenticated HTTP tests using real `RefreshDatabase` fixtures.
- Positive literal percent + underscore search, false-positive exclusion against wildcard-shaped text, escaped exclamation plus underscore, ordinary substring, absent matches, public-id and territory filters.
- Authenticated `SUPER_ADMIN` and denied roleless actor, Arabic/English Accept-Language, unchanged pagination envelope, read-only queries and no review event insertion.
- Required final head: PHP syntax, Pint, PHPStan/security, Laravel unit + feature, bilingual localization and applicable MySQL/Redis acceptance; independent Repository Policy, V2 Isolation Gate and terminal Required CI Gate green on exact final PR SHA.
- CI run links and merge SHA must be recorded before claiming code-complete. Source/manual integration acceptance remains tracked in #2503.

## Surface and mobile parity matrix

| Surface | Impact | Contract |
|---|---|---|
| Backend/Admin API | Affected: literal search interpretation only | Existing customers.view Gate, request validation, response schema and read-only semantics |
| Dashboard Field Operations | Existing queue and search call; no source UI alteration | Existing permission, AR/EN, RTL/LTR, keyboard/accessibility and responsive behavior |
| Customer app | Not affected | No customer route, auth, payload, or Flutter code changed |
| Driver app | Not affected | No driver route, B2C delivery, or Flutter code changed |
| Van app | Not affected | No van route, B2B dispatch/custody, or Flutter code changed |

## Ownership fence and pending helper convergence
This leaf changes only `AddressQualityController`, its new focused test and this QA doc. Original open PR #3546/#3545 independently owns `Enterprise/Search/LiteralLikePattern.php`; it is neither imported from an unmerged branch nor copied into a duplicate shared helper. After #3546 is integrated under its own required gates, a subsequent scoped refactor could converge reusable escaping if desired; this leaf does not overwrite or depend on another worker's source.
