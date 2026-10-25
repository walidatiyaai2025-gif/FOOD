# FOODEX Dashboard Update 1.0.106

Date: 2026-10-24

## Scope

Dashboard/Public Web only. Customer, Driver and Van Android source identities remain 1.0.104+104 and are not rebuilt.

## Included fixes

- Public Wholesale Web widescreen polish carried forward from the finalized 1.0.105 source lineage.
- Alexandria loaded from Google Fonts.
- Accessible animated hamburger navigation.
- Canonical FOODEX icon for header, footer, favicon, product/category/brand image fallbacks.
- Public content shell expanded to 1560px with denser large-screen catalog layout.
- Customer App public download endpoint now prefers the verified local APK mirror and falls back immediately to the immutable official GitHub Release APK when the local mirror is not ready, while still scheduling the mirror in the background.

## Deployment

Install through Dashboard > System Update using the generated FOODEX-Update.zip and the matching manifest/checksum. This version is intended for systems already on 1.0.105 as well as earlier supported versions.
