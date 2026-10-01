# Preview Runtime Production Distribution

Issue: #590  
Parent coordination: #589

FOODEX ships the Customer and Driver Dashboard previews as the real shared Flutter Web runtimes. This document defines the production distribution contract. It does not claim that a deployment has occurred.

## Canonical production targets

- API base URL: `https://foodex.50sols.com`
- Dashboard parent origin: `https://foodex.50sols.com`
- Preview runtime origin: `https://foodex.50sols.com`
- Contract: `shared-flutter-v1`
- Customer base href/runtime path: `/preview/customer/`
- Driver base href/runtime path: `/preview/driver/`

The Dashboard configuration must therefore resolve to:

```text
FOODEX_CUSTOMER_PREVIEW_RUNTIME_URL=https://foodex.50sols.com/preview/customer/
FOODEX_CUSTOMER_PREVIEW_ALLOWED_ORIGIN=https://foodex.50sols.com
FOODEX_CUSTOMER_PREVIEW_CONTRACT_VERSION=shared-flutter-v1

FOODEX_DRIVER_PREVIEW_RUNTIME_URL=https://foodex.50sols.com/preview/driver/
FOODEX_DRIVER_PREVIEW_ALLOWED_ORIGIN=https://foodex.50sols.com
FOODEX_DRIVER_PREVIEW_CONTRACT_VERSION=shared-flutter-v1
```

These values are a supported production configuration contract, not evidence that the URLs are already deployed.

## Build and package path

`.github/workflows/preview-runtime-distribution.yml` is the authoritative build/distribution workflow. Required CI calls it when distribution files change. Version tags also generate the package, and an operator can run it manually.

The workflow:

1. Builds `apps/customer_app/lib/preview_main.dart` with the Customer base href.
2. Builds `apps/driver_app/lib/preview_main.dart` with the Driver base href.
3. Uses the production API URL, production Dashboard parent origin, and `shared-flutter-v1`.
4. Packages both runtime trees with `scripts/package-preview-runtimes.py`.
5. Generates deterministic, version-and-commit identified archives and SHA-256 digests.
6. Generates `preview-runtime-manifest.json` and `preview-runtime.env`.
7. Serves the packaged tree locally and fails if `index.html`, `main.dart.js`, Flutter bootstrap, or assets do not resolve at the exact Dashboard target paths.

The artifact layout is:

```text
dist/preview-runtime/
  README.txt
  preview-runtime.env
  preview-runtime-manifest.json
  foodex-customer-preview-<version>-<commit>.tar.gz
  foodex-driver-preview-<version>-<commit>.tar.gz
  www/
    preview/
      customer/
      driver/
```

Do not rename the deployed `preview/customer/` or `preview/driver/` directories without rebuilding with matching base hrefs and updating Dashboard configuration.

## Deployment

The generated `www/` contents are deployment-ready static assets. Copy them to the production web root so the canonical paths remain unchanged. Apply the generated `preview-runtime.env` values to the Dashboard environment and refresh Laravel configuration using the normal production deployment procedure.

Do not place preview-session tokens, Customer/Driver credentials, database secrets, or API keys in the static bundle, runtime URL, or manifest.

## Production smoke evidence

Repository CI proves the package layout and HTTP contract locally. It does not prove production deployment.

After the static files are externally deployed, run the **Preview Runtime Distribution** workflow manually with `verify_deployed=true`. That gate verifies both production runtime URLs and their `main.dart.js`/Flutter bootstrap assets over HTTPS.

Only a successful post-deployment smoke run (or equivalent captured production evidence) may be recorded as proof that production runtime URLs are live.
