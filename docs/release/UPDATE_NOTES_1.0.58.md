# FOODEX 1.0.58 Release Notes

Status: repository-controlled Store Submission Readiness release for Issue #990. This does not claim Apple App Store or Google Play approval/publication.

## Release identity

- Dashboard: `1.0.58`
- Customer: `1.0.58+58`
- Driver/Van: `1.0.58+58`
- Production API: `https://foodex.50sols.com`

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

PR #994 remains unmerged. No auto-merge or merge to `main` is authorized by #990.
