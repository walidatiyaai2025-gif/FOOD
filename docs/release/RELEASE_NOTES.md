# FOODEX 1.0.61 Release Notes

Status: production-domain mobile hotfix release for #1062. Rebuild Customer, Driver and Van from the final main integration so the actual APK binaries target `https://foodex.50sols.com` and reject the obsolete `vanfoodex.50sols.com` endpoint.

Release branch: `release/1062-mobile-domain-hotfix`

## Release identity

- Dashboard / repository release: `1.0.61`
- Customer app: `1.0.61+61`
- Driver/Van app: `1.0.61+61`
- Customer runtime/footer identity: `1.0.61`
- Driver runtime/footer identity: `1.0.61`
- Driver diagnostics current identity: `1.0.61`
- Driver diagnostics build identity: `58`

- Production API: `https://foodex.50sols.com`

## Included changes

- Rebuild all three Android APKs from the final `main` implementation with `FOODEX_API_BASE_URL=https://foodex.50sols.com` and add binary-level release validation that fails if `vanfoodex.50sols.com` is embedded.
- Fix Van Arabic mobile navigation so the hamburger and overlay drawer are on the physical left; selecting a menu item closes the drawer before showing the selected screen, eliminating nested dashboard navigation.

- Include the canonical commercial policy engine, break-pack rules, feature flags, Flash lifecycle/audience targeting, Dashboard preview/analytics, Customer Flash checkout and Van online-only Flash/commercial enforcement from #983/#991.

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
