# V2 #3480 — Half-open Van / territory primary assignment intervals

- Source contract: #2738 (Van B2B assignment authority), #2503 (V2 final acceptance); deploy target `Foodex_V2` ONLY.
- Canonical domain: `VanRegistryService::assign()` transactional create; the same class's `updateAssignment()` already uses the correct half-open interval test.
- Original defect: an existing `[Jan 10, Jan 20)` active primary assignment was not detected as overlapping with a proposed `[Jan 15, Jan 25)` because the prior create query compared the **existing end** to the **proposed end**. A shorter existing reservation falsely escaped both Van ownership and same-territory cross-Van checks.
- Guard: `existing.effective_from < proposed.effective_until` for bounded proposals, plus `existing.effective_until IS NULL OR existing.effective_until > proposed.effective_from` for all proposals. This is exactly the *half-open* intersection rule. The existing transactional `lockForUpdate`, active/primary filters, Van identity, different-Van territory check, and original `ValidationException` field keys remain in place. `effective_until = null` stays unbounded; historical non-overlapping intervals remain valid.

## QA / negative and positive acceptance

`V2VanAssignmentFiniteOverlapTest` (Laravel / database-backed) verifies:
1. Shorter existing finite primary Van assignment blocks a later overlapping longer window with a field-scoped `assignment` validation error.
2. Shorter existing finite primary territory reservation blocks a different Van with a field-scoped `territory_key` validation error.
3. Boundary adjacency `[start,end)` on either side is valid for the same Van; same territory can transition to a different Van at exact end instant.
4. A historical finite reservation may be followed by an open-ended reservation.
5. Rejections create neither additional assignments nor assignment-created audit events. No stock movements, dispatch transitions, financial ledgers, or uploads are changed.

**Authorization / cross-surface impacts**:
- Backend: the existing authorized Van assignment create path and its current validation/error codes are unchanged; only the correctness of the effective-date overlap decision changes. Existing B2B-only active Driver and linked-user checks from #3433 remain unchanged.
- Dashboard: existing Van assignment form/API entry and existing validation messages/AR/EN are reused. No new route, menu, UI wording, RTL/LTR or responsive layout changes.
- Customer: no public Customer API, order, auth, Flutter or navigation change. B2C continues **DRIVER ONLY**.
- Driver: no Driver API/Flutter, wallet, delivery state or dispatch change.
- Van: Van assignment source authority becomes stricter for invalid overlapping finite windows; existing successful response shape and Van app Flutter code are unchanged. Existing V2 Van implementation and manual journey acceptance remain tracked under #2738.
- Security: reject conflicting double ownership; same effective periods and role/tenant/maker-checker checks remain upstream. No permission expansion, cross-store or B2C fallback. No production claim.

**Limitations**: These are deterministic interval query fixes. They retain the existing transactional row locks and do not claim independent proof against concurrent *empty-set* inserts. Concurrent first-writer serialization is a separate review if demonstrated; it must not be inferred from this source fix or green CI.

**CI gate**: verify final exact HEAD Repository Policy, FOODEX V2 Isolation and Required CI (Laravel, Pint/PHPStan/security, localization and MySQL/Redis where applicable) all terminal SUCCESS before merging this atomic PR into Foodex_V2. Close #3480 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only after verified merge SHA/test evidence; parent #2738/#2503 remain OPEN. No main, release/tag, production deployment or live cPanel changes.
