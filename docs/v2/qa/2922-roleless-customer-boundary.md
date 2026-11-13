# V2 #2922 — Historical row 17: roleless customer / Dashboard boundary

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, original native Table 5 data row 17; parent #2503.

**Historical fact:** Row 17 has no assigned Dashboard RBAC role and zero distinct inherited role permissions in the archived export. This is not an attestation of current identity type, live account state or effective access. Do not publish the exported identifier, credentials, phone, email or any current user identifiers, and never delete/reassign a real account from an old export.

## Existing authority and placement

Reuse Security Center, existing roleless user filter and B2B/B2C administrative order endpoints. No new UI, route, sidebar, permissions, controller or migrations. An account's customer flag does not confer administrative privileges. Conversely, lacking Dashboard roles is not grounds for automatically disabling, deleting or assigning privileges to a legitimate customer account.

The additive test **backend/tests/Feature/V2ExportedRolelessRow17BoundaryTest.php** uses synthetic records only:

1. An active, roleless synthetic customer cannot open Wholesale or Retail order lists or submit administrative order creations with genuine B2B/B2C store IDs. Both Arabic and English sessions must return 403.
2. There are zero global/store role assignments, no inherited/effective Dashboard permissions, no B2B/B2C order-management scope, no privileged Sidebar group, and no inserted role/permission overrides. The synthetic customer account stays active.
3. A Super Admin may inspect the roleless customer classification in the existing Security Center in EN/LTR and AR/RTL; the customer cannot view Security Center or role management.

**Difference from #2923:** Row 18 already covers synthetic classification transition and inventory isolation; this row-17 regression covers independent order read **and write** boundaries without classifying or mutating the original identity.

## Authorization and application-impact matrix

| Surface | Classification / evidence |
| --- | --- |
| Backend | Synthetic authenticated order-authorization GET/POST tests only |
| Dashboard | Existing Security Center roleless filter and roleless Sidebar projection; no UI change |
| Customer | No Flutter, authentication or public API code change; customer classification does not grant Dashboard rights |
| Driver | No delivery API/Flutter or role modification; Retail B2C remains DRIVER ONLY |
| Van | No Van API/Flutter or role modification; Wholesale B2B remains VAN ONLY |
| AR/EN, RTL/LTR | Existing Security Center views exercised under both locales |
| Data integrity | Test fixtures only; no live users, orders, uploads, finance records or credentials changed |

No cross-app issue is required for this test-only delta. If authorized live reconciliation finds a public API impact, open/link a separate V2 mobile issue before implementation.

## Outstanding source-of-truth acceptance

An appropriately authorized reviewer must perform **read-only** current-data reconciliation of the confidential archived account: identity classification, active status, global/store roles, company/store/channel scope, permission ALLOW/DENY overrides, effective permissions and the actual Sidebar. Store account-specific evidence in a restricted location, never in this public repository. The synthetic test does **not** establish the current type or status of that historical account, so keep #2922 open until that acceptance is evidenced.

## Git and CI gates

PR base **Foodex_V2 ONLY**, one issue/one branch/one PR. Required green evidence on the exact final head: Repository Policy, FOODEX V2 Isolation, FOODEX Required CI with Laravel, PHP syntax/Pint/PHPStan/security, AR/EN and applicable MySQL/Redis fresh-install/upgrade/recovery. Do not merge if any gate is pending, skipped without justification or failed. Manual real-browser/device UAT follows #2503/#2750 separately and never waives technical CI.

No merges to main, tags, release, cPanel deployment or production changes.
