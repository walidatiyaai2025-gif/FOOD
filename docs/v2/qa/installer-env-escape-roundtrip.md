# FOODEX V2 #3400 — Installer .env backslash escape roundtrip

Source and acceptance lineage: P112/G004 #2745, #2846; V2 master #2503. Integration target: **Foodex_V2 ONLY**. No main, production, tags or release promotion.

## Exact source defect

The web installer's \`InstallerEnvironment::encodeValue()\` writes backslashes escaped inside double-quoted \`.env\` values. Its previous \`decodeValue()\` used ordered, sequential \`str_replace\`: the first replacement for \`\\n\` or \`\\r\` could consume the second slash of an encoded pair \`\\\\n\` or \`\\\\r\`, producing control characters where the original password contained literal backslash-plus-letter. The later \`\\\\\` replacement cannot reverse that mutation. A local PHP reproducer confirmed \`"secret\\\\nTXT"\` read as one slash + actual LF instead of literal slash + \`n\`. This is a value-roundtrip bug, not a claim that anyone can access the stored secrets or bypass installer authorization.

## Scope and behavior

\`InstallerEnvironment::decodeValue()\` now scans a quoted value **once**: \`\\n\` and \`\\r\` produce their intended control characters, \`\\\"\` produces a quote, \`\\\\\` produces a single backslash, and any unknown escape remains literal. A newly decoded slash is never scanned again. Existing environment allowlist, credential quoting, duplicate managed-key canonicalization, atomic write, file mode 0600 and symlink rejection remain unchanged.

New isolated Laravel Feature test \`V2InstallerEnvEscapeRoundtripTest.php\` covers:

- Initial append of synthetic DB, Redis and SMTP credentials containing literal backslash followed by \`n\` / \`r\`, multiple slashes and embedded quotes.
- Replacing existing credential assignments and replaying an update, without losing values or rewriting \`APP_NAME\`.
- Genuine newline and carriage-return characters, escaped quotes, unknown escape preservation, no physical newline injected into the serialized .env and 0600 permissions.

No real password, customer data or production \`.env\` is read or written. Every test creates and deletes an isolated \`storage/framework/testing\` .env fixture.

## User-facing placement and cross-app impact

| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend | Existing first-run web setup environment parser | Only installer .env decoding changed |
| Dashboard | Existing /install flow, no navigation or UI changes | No Blade, routes, translation changes |
| Customer | Not affected | No public API, identity, commerce or Flutter changes |
| Driver | Not affected | No API, wallet, dispatch or mobile changes |
| Van | Not affected | No API, consignment, route or mobile changes |
| AR/EN, RTL/LTR | Not affected | No user-facing labels or layout changes |

B2B is VAN ONLY and B2C is DRIVER ONLY as before.

## Acceptance and limitations

Independent final-head CI must prove Repository Policy, FOODEX V2 Isolation, FOODEX Required CI, PHP syntax/Pint/PHPStan/security/OpenAPI, Laravel full suite, AR/EN and actual MySQL/Redis install/upgrade/recovery all completed SUCCESS. Do not claim a green check before the GitHub Actions terminal result. This atomic fix is not full G004/P112 signed Setup ZIP/Update ZIP delivery or production deployment. Parent #2745, #2846 and #2503 remain OPEN until separate package/source acceptance.
