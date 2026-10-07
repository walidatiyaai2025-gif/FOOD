# Customer Preview Web Runtime

This target hosts the **real** `FoodexCustomerApp.preview(...)` runtime used by
the FOODEX Management Dashboard Preview Center. It is not a second Customer UI.

Build example:

```bash
flutter build web \
  --release \
  --target=lib/preview_main.dart \
  --base-href=/preview/customer/ \
  --dart-define=FOODEX_API_BASE_URL=https://vanfoodex.50sols.com \
  --dart-define=FOODEX_PREVIEW_PARENT_ORIGIN=https://vanfoodex.50sols.com \
  --dart-define=FOODEX_PREVIEW_CONTRACT_VERSION=shared-flutter-v1
```

The deployed runtime URL/origin is then configured on the Dashboard with:

```text
FOODEX_CUSTOMER_PREVIEW_RUNTIME_URL=https://preview.example/customer/
FOODEX_CUSTOMER_PREVIEW_ALLOWED_ORIGIN=https://preview.example
FOODEX_CUSTOMER_PREVIEW_CONTRACT_VERSION=shared-flutter-v1
```

Security contract:

- The parent Dashboard origin is a build-time allowlist value.
- The iframe accepts only `foodex.preview.bootstrap` from that exact origin.
- Authenticated preview credentials remain only in
  `CustomerPreviewReadHttpClient` and are sent as
  `X-Foodex-Preview-Token` to preview-authorized read routes.
- The credential is never stored in `CustomerSession.accessToken`, URL/query
  parameters, local storage, status messages or visible UI.
- Preview is read-only; mutations are blocked by the shared Customer preview
  policy.

Production packaging is not a manual local-build contract. Use
`.github/workflows/preview-runtime-distribution.yml`, which builds Customer and Driver
preview runtimes together, produces version/commit-identified archives and a manifest,
and validates the exact Dashboard deployment paths. See
`docs/release/PREVIEW_RUNTIME_DISTRIBUTION.md`.

