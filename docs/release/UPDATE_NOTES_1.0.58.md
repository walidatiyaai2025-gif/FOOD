# FOODEX 1.0.58 Release Notes

Status: canonical FOODEX Van 1.0.58 release candidate with #983/#991 commercial/Flash verification and #990 store-submission readiness. Apple/Google console/signing actions remain external.

## Release identity

- Dashboard: `1.0.58`
- Customer: `1.0.58+58`
- Driver/Van: `1.0.58+58`
- Production API: `https://vanfoodex.50sols.com`

## Commercial and Van consolidation

- Canonical commercial policy, selling units, quotas, break-pack enforcement, explicit overrides and feature flags.
- Flash lifecycle, audience targeting, Customer popup/push/Buy Now, Van push-only online behavior, reservation expiry/release and server-authoritative time.
- Dashboard Product Sales Control, Flash management, Preview and Analytics, plus cross-channel finance/warehouse/order snapshots.
- Complete Van field-operations lineage including collections, remittance and reconciliation.

## Store-submission readiness

- Extend the existing Mobile Store & Push Settings Center with Customer Android/iOS and Driver/Van Android/iOS submission lanes.
- Add encrypted write-only reviewer credentials, deterministic persona checks and masked Dashboard rendering.
- Add public Privacy, Terms, Support and Account Deletion surfaces.
- Add authenticated Customer account-deletion/anonymization with retention safeguards; Driver/Van operational identities use managed deactivation/review semantics.
- Make persistent version/footer visibility Dashboard-configurable without allowing runtime settings to falsify the installed binary identity.
- Add canonical metadata, permission/privacy checklists and deterministic PASS/WARN/BLOCKED readiness evidence.
- Add Android release AAB validation with explicit non-debug signing checks using validation-only ephemeral CI keys.
- Keep Apple/Google console access, production signing/provisioning, final screenshots/assets upload and final store privacy declarations explicitly external/manual.

## Dashboard update bundle

- Package: `Release/Updates/FOODEX-Update.zip`
- Target version: `1.0.58`
- Minimum supported current version: `1.0.6`
- Contains migrations: **Yes**
- Full redeploy required: **No**
- The package/checksum/manifest and release-registry SHA are generated and validated as one atomic release publication unit.

## Merge safety

The canonical branch remains `release/1.0.58-van-complete`. No auto-merge or merge to `main` is authorized.
