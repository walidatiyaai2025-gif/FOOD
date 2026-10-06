# FOODEX 1.0.58 Release Notes

Status: Issue #990 store-submission readiness implementation — repository-controlled work only. This does not claim Apple App Store or Google Play approval/publication.

Release branch: `feat/983-store-submission-readiness`

## Release identity

- Dashboard / repository release: `1.0.58`
- Customer app: `1.0.58+58`
- Driver/Van app: `1.0.58+58`
- Customer runtime/footer identity: `1.0.58`
- Driver runtime/footer identity: `1.0.58`
- Driver diagnostics current identity: `1.0.58`
- Driver diagnostics build identity: `58`

## Included changes

- Extend the existing Mobile Store & Push Settings Center with four independent publishing lanes: Customer Android, Customer iOS, Driver/Van Android and Driver/Van iOS.
- Track package/bundle identifiers, version policy, store/legal URLs, bilingual release notes, descriptions, category/keywords, reviewer notes, asset/privacy checklists, submission status, and PASS/WARN/BLOCKED readiness without creating a competing settings subsystem.
- Add Dashboard-controlled persistent footer visibility while keeping the installed binary version authoritative and retaining version/build access through diagnostics.
- Add encrypted, write-only store reviewer/test credentials with separate Customer and Driver/Van personas, rotation, deterministic instructions and server-side readiness verification.
- Add production-accessible Privacy, Terms, Support and Account Deletion routes.
- Add verified Customer account-deletion/anonymization with token revocation and retention gates for active orders/finance; Driver/Van operational identities follow managed deactivation/retention semantics.
- Add canonical store submission metadata, permission-impact declarations, deterministic repository readiness auditing and external/manual separation.
- Add an explicit Android store signing path that refuses debug fallback and validate Customer/Driver Android App Bundles with ephemeral non-debug CI release keys marked validation-only.
- Preserve existing Firebase/push infrastructure and production application identifiers.

## External manual actions still required for actual store submission

- Google Play Console access, app ownership, Play App Signing/upload-key production credentials and final Data Safety submission.
- Apple Developer/App Store Connect access, distribution certificate/provisioning profile and final archive/signing.
- APNs/Firebase console association confirmation where console credentials are unavailable to repository CI.
- Final store screenshots/promotional assets upload and final store privacy/content declarations.

## Explicit non-activation statement

This release branch does **not** automatically:
- merge to `main` or enable auto-merge;
- submit or publish either app to Apple/Google stores;
- change production minimum-supported-version or force-update policy;
- enable Driver location enforcement;
- expose any reviewer password, signing credential, certificate or private key in repository/UI/API/logs.
