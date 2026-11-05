# FOODEX 1.0.123 Update Notes

- **Upgrade source:** Dashboard 1.0.121 (and other supported earlier versions) through the Admin Dashboard System Updates uploader; update package manifests must explicitly declare minimum-current-version compatibility.
- **Target:** Dashboard / Laravel API 1.0.123, with automatic migration and protected data/credentials/media preservation.
- **Commerce:** live wholesale minimum-order setting defaulting to 1,500 EGP (0 disables), live available-stock filter, selling-unit-aware cart, checkout eligibility and UI errors.
- **Customers and drivers:** payable/paid amounts, order screens, offer tabs and Flash Offers product discovery, driver order navigation/proof selection and invoice/print FOODEX branding.
- **Mobile:** synchronize Customer, Driver and Van versions to 1.0.123+123, including iOS scanner compatibility changes; mobile binaries are separate from the Dashboard ZIP.
- **Safety:** require backup, trusted package verification, release notes, SHA-256, protected paths and no concurrent update. Preserve .env, site data and uploads. A failed migration must use the updater's documented recovery protocol rather than assuming automatic SQL rollback.
- **Acceptance:** canonical Release Factory must pass exact-source Setup ZIP fresh install, Dashboard package validation, release-artifact digest matching and final protected-main registry sync before the new version is considered published. Preserve older immutable Releases.
