# V2 #2743 — G002/P385 Native-Word source-to-Issue traceability

## Scope and provenance
- Original private reference: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx (Library original, **not** committed to Git or CI artifacts).
- Source SHA-256: ea6e6eefa006831c3e90604cde05ec4b5b95d42fe21fab68604f6fe18c5f1757.
- Original Word structure independently inspected: **374 top-level paragraphs**, **13 native tables**; table row counts including header: [8,9,7,5,6,21,5,7,4,6,25,9,13].
- Source mapping decisions: docs/v2/V2_2743_SOURCE_REVIEW.json.
- Existing canonical source matrices remain unmodified; this is not a duplicate Issue inventory.

## Source structural audit
| Source class | Linked to V2 child Issue | Explicitly reviewed context |
| --- | ---: | ---: |
| Native paragraphs P000–P373 | 300 | 74 |
| Native table data rows | 101 | 11 (Tables 0 and 6) |
| Table header rows | n/a | 13 non-actionable |
| Derived cross-app and gate issues | 21 | relational, not invented native pointers |
| **Total child Issue links** | **422** | no additional Issues generated |

The 74 unlinked paragraphs are individually classified as metadata, headings or explanatory source/architecture notes. The two unlinked tables are summary/restatement tables with references to already mapped tasks. Every native source anchor is either mapped or reviewed. The strict audit fails closed on missing, duplicate, overlapping or out-of-bounds source references, invalid derived Issue identities, and source SHA/structure mismatch.

Sidebar matrix document_paragraph_id is the native Word paragraph, distinct from the historical issue index P174–P350. Native table numbering is zero-based; data rows begin at 1 after a header.

## Reproduction
- Repository-only: python3 scripts/audit_v2_source_coverage.py --report artifacts/v2-2743-source-coverage.json
- Negative tests: python3 -m unittest discover -s scripts/tests -p 'test_v2_source_coverage.py' -v
- Authorized source verification (offline with exact original): python3 scripts/audit_v2_source_coverage.py --source /private/path/FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx --report /tmp/v2-source-verification.json

CI intentionally reports MATRIX_REVIEW_ONLY when the original private source is absent. SHA/Word structure verification of the supplied original is separately reported as SOURCE_SHA_AND_STRUCTURE_VERIFIED. Reports never contain source text, exported account rows or secrets.

## Acceptance / impacts
This audit proves structural source coverage, **not** actual completion, authorization or CI success of all 422 child Issues. Leaf functionality, HTTP RBAC, tenancy, ERP operations, device UI/UX and release artifacts remain tracked by those Issues and parent #2503.

- Backend runtime/API/permissions: unchanged. Dashboard runtime/sidebar/routes: unchanged.
- Customer app: no app, API contract or mobile runtime impact.
- Driver app: no app, API contract or mobile runtime impact.
- Van app: no app, API contract or mobile runtime impact.
- V2-only CI: adds source-coverage verification and negative unit tests. No shared in-flight PR file fence changed.
- Full owner/UAT remains on #2503 and final V2 gates. All required PR checks must pass on the last SHA before any Foodex_V2-only integration.
- Absolutely no main merge, release/tag, production deployment or changes to production accounts.
