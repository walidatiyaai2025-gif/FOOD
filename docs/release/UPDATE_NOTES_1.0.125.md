# FOODEX 1.0.125 Update Notes

- **Supported direct upgrade:** FOODEX Admin Dashboard `1.0.124 → 1.0.125`. Do not apply this ZIP directly to older releases unless the published manifest explicitly permits their source version.
- **Customer groups:** authorized B2B/B2C administrators can manage and activate scoped groups, add/remove individual customers in multiple groups, and bulk-apply membership changes across selected groups without destroying unrelated memberships. Targeting honors channel/store membership.
- **Customer service:** multiple employees may own one order's follow-up concurrently; assignments retain history and role/store access boundaries.
- **Controlled order discount:** while an order is in the authorized pending Customer Service stage before dispatch, a permitted employee can enter a percentage discount and mandatory note. Every revision is audited; retries use idempotency and the customer is notified of the revised total. Issued/paid/dispatched orders are excluded.
- **Wholesale mobile home:** published storefront configuration is consumed by the current wholesale Customer App Home; draft preview remains isolated from live configuration.
- **Platform Owner:** a separately registered internal owner may inspect platform modules while normal Super Admin remains distinct. Provisioning requires privileged internal deployment credentials; no web registration or predictable default password is provided.
- **Safety:** preserve `.env`, production data, uploaded assets, user accounts, store segregation, B2B VAN ONLY and B2C DRIVER ONLY. The Update Center must check signed package origin, integrity, source version and backup/recovery prerequisites.
- **Release assets:** synchronized FOODEX Customer, Driver and Van `1.0.125+125` APKs; Setup ZIP, Update ZIP, Manifest, SHA-256, Build Info and Release Notes. All must be generated from the final protected-main SHA and pass actual fresh-install and upgrade acceptance. Neither this note nor passing PR CI proves the release has been published or installed on production.


## Build infrastructure repair (#2484)

- Release Factory retains `1.0.125` and the exact source commit. The reusable mobile release builder pins the generated Gradle wrapper to the official `downloads.gradle.org` distribution CDN, preserving the generated version, ZIP identity, APK package IDs, and build numbers.
- Only transient HTTP 5xx Gradle/Maven dependency download errors receive at most two retries. Compiler errors, unexpected wrapper URLs, integrity failures, and wrong APK identities remain hard failures. This repairs repeated external 504 failures seen in Release Factory run `42469823957` without weakening acceptance gates.
- A change to the reusable mobile builder triggers Release Factory on protected `main`, so each built asset and fresh-install/upgrade verification still comes from the same finalized 1.0.125 source SHA.
