# Preview Runtime Distribution

Issues: #590, #657

FOODEX previews the real shared Customer and Driver Flutter applications. A Dashboard update must never replace them with a Blade/HTML mock renderer.

## Standard Update Center path

The standard production path is self-contained. `.github/workflows/release-update-bundle.yml` builds both Flutter Web preview entrypoints before creating `FOODEX-Update.zip` and stages them at:

```text
backend/public/preview/customer/
backend/public/preview/driver/
```

The normal updater extracts those files with the rest of the Dashboard release, backs up any previous copies, normalizes public-asset permissions, rebuilds Laravel caches, and restores the previous files on rollback.

The update bundle is invalid unless both runtime trees contain:

- `index.html`
- `main.dart.js`
- `flutter_bootstrap.js`
- a non-empty `assets/` payload

The runtime base hrefs are fixed to `/preview/customer/` and `/preview/driver/`, so the files must remain at those public paths inside the FOODEX installation.

## Same-origin configuration

For the standard installation no preview-specific environment variables are required.

`backend/config/app_preview.php` derives the defaults from `APP_URL`:

```text
Customer runtime = {APP_URL}/preview/customer/
Driver runtime   = {APP_URL}/preview/driver/
Allowed origin   = origin(APP_URL)
Contract         = shared-flutter-v1
```

The bundled browser hosts also default their API and parent-message origin to the runtime page's own origin. This keeps a normal installation portable across the configured `APP_URL` while preserving exact-origin message validation.

Explicit overrides remain supported for advanced deployments:

```text
FOODEX_CUSTOMER_PREVIEW_RUNTIME_URL
FOODEX_CUSTOMER_PREVIEW_ALLOWED_ORIGIN
FOODEX_CUSTOMER_PREVIEW_CONTRACT_VERSION
FOODEX_DRIVER_PREVIEW_RUNTIME_URL
FOODEX_DRIVER_PREVIEW_ALLOWED_ORIGIN
FOODEX_DRIVER_PREVIEW_CONTRACT_VERSION
```

An externally hosted preview build can additionally compile `FOODEX_PREVIEW_API_BASE_URL` and `FOODEX_PREVIEW_PARENT_ORIGIN`.

## Optional standalone distribution

`.github/workflows/preview-runtime-distribution.yml` remains available when the preview runtimes intentionally need to be hosted outside the standard Dashboard update tree. It builds version/commit-identified archives, a manifest, checksums, and an environment snippet and can run an external HTTPS smoke check.

That standalone workflow is optional. The supported default is Update Center deployment from the same `FOODEX-Update.zip` as the Dashboard.

## Release invariant

Do not publish a Dashboard update when either preview runtime failed to compile or is missing from the generated package. The release workflow must fail before publication rather than leave App Preview in an unbound state.
