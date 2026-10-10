# Skill: Release

Mandatory authority: `docs/release/RELEASE_ARTIFACT_CONTRACT.md`.

## Preflight
- Identify exact release Issue/branch and source SHA.
- Ensure required implementation/convergence gates are satisfied.
- Re-read root `VERSION`.
- Verify no newer business-code commit made existing evidence/artifacts stale.

## Required synchronized release set
A completed FOODEX release accounts for:
- versioned Customer APK;
- versioned Driver APK;
- versioned Van APK;
- compatibility/latest aliases where required;
- `LATEST_RELEASE.json`;
- `BUILD_INFO.json`;
- Dashboard `Release/Updates/FOODEX-Update.*` assets;
- `FOODEX-Laravel-Setup.zip` and fresh-install evidence when required.

A release fails if one first-class mobile app is missing.

## Source identity
All artifacts for one release must identify the intended same version/source lineage.

If implementation changes after a frozen source SHA:
- previous Setup/evidence is stale;
- rebuild/revalidate affected artifacts;
- never move an immutable published tag merely to hide lineage drift.

## Validation
Check app identity/version/build, manifest filenames, checksums/sizes, Update Center assets, Setup ZIP source identity, clean install from ZIP only when required, exact-head required CI and intended production API endpoint/configuration.

## Publication
Publish only after required gates pass. Packaging success does not replace UI/runtime acceptance, and UI/runtime acceptance does not replace packaging integrity.
