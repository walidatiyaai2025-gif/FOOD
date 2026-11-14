# V2 #3365 — P384 complete current Sidebar registry bilingual label QA

## Source and nonduplication
- Parent #2904 (source DOCX native P371 / legacy matrix P384), master #2503; isolated integration target `Foodex_V2` only.
- The original #2904 / PR #2944 already standardized **seven selected Arabic label keys** and included a focused Laravel test. It is not recreated or edited.
- The current canonical `backend/config/admin_sidebar.php` contains **21 business groups + 69 item translation labels = 90 current registered labels**. This separate atomic child tests the entire present registry, not the *future target 177-node* Sidebar tree. It does not certify activation/readiness of future ERP capabilities.

## Delivery / evidence
- `backend/tests/Feature/V2P384SidebarRegistryLabelCoverageTest.php` asks the actual Laravel `AdminSidebarManager::registry()` for every configured group/item label; it does not hardcode a hand-picked translation whitelist or infer current user-specific saved database overrides.
- Every registered key must exist independently in both `ar` and `en` with fallback disabled. Both resolved labels must be nonempty, not the raw translation key, and use the selected language (Arabic business text may include approved FOODEX/ERP/QR/B2B tokens; English text must not leak Arabic system strings).
- A second test switches the selected locale and proves the same registered keys resolve in that locale. Pinning the current 90-entry baseline makes an unexplained regression in current Sidebar coverage visible in CI. Future deliberate navigation changes must update this documented inventory and the test together.
- Existing separate coverage for saved bilingual Sidebar overrides, route authorization, tenant/channel scope, module readiness and responsive web remains authoritative. This test does not invent a parallel navigation security policy.

## Runtime/product impact matrix
- **Dashboard:** Existing role-authorized Sidebar only; no rendered markup, route, feature permission, visual responsive behavior, item status or database mutation changed by this test.
- **Backend:** additive read-only PHPUnit/translation regression only; no API/controller/schema change.
- **Customer:** no Flutter, public API or contract delta.
- **Driver:** no Flutter, public API or contract delta; Retail/B2C Driver-only unchanged.
- **Van:** no Flutter, public API or contract delta; Wholesale/B2B Van-only unchanged.

## Acceptance
One branch and PR for this atomic child, base `Foodex_V2`. Exact-head Repository Policy, FOODEX V2 Isolation Gate, AR/EN, Laravel/Pint/static/security and Required CI must all succeed. Merge and close **only #3365** after verifying the isolated SHA; leave #2904 and master #2503 open for wider source acceptance. No `main` merge, tags, releases, cPanel or production actions.
