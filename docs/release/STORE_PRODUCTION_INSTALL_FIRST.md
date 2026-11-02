# FOODEX — permanent production store / first install

**This lane is not the demo lane.** `release/store-production` was created on 2026-11-02 from `main` at `2d5501e37fc428e4bc499eeabbb47733d794003b` (v1.0.122). Its sole runtime origin is **https://store.foodexeg.com**.

## Branch retention contract

- `release/store-production` is permanent, owner-designated, and must not be deleted by branch cleanup. `release/store-demo` remains separate and unchanged.
- The cleanup workflow and branch-policy allowlist recognize this branch. **This is not an immutable GitHub deletion guarantee.** An administrator must configure GitHub Settings → Rules → Rulesets to target the exact `refs/heads/release/store-production` and restrict deletion and non-fast-forward updates, with no unintended bypass, then verify the active ruleset. The GitHub Connector cannot currently write rulesets.
- Advance the branch from validated `main` by fast-forward only. Do not auto-update it from every PR or share source assets/artifacts with the demo lane.

## Obtain the first-install package

1. Open GitHub Actions → **FOODEX Store Production Distribution** on `release/store-production`. A push to this branch builds the release; it is also manually dispatchable from the workflow UI.
2. Download the single `FOODEX-Store-Production-<version>-<SHA>` artifact when all workflow jobs pass. Inside: the `FOODEX-Store-Production-<version>-<SHA>.zip` distribution archive.
3. Extract the distribution archive: it contains `FOODEX-Laravel-Setup.zip`, `FOODEX-Customer-<version>.apk`, `FOODEX-Driver-<version>.apk`, `FOODEX-Van-<version>.apk`, `BUILD_INFO.json`, `SHA256SUMS`, first-install instructions and optional fresh-install evidence.
4. All APKs and the Laravel ZIP originate from exactly the same workflow `GITHUB_SHA`. Validate checksums; never substitute APKs from `Release/` or `release/store-demo` since those target the old site.
5. APKs are Android **device-acceptance builds** until a stable production signing key and associated release/store publication credentials are explicitly supplied. Do **not** publish debug-key APKs to Google Play or claim they are certified store builds. The original Android application IDs are unchanged.

## First-time Laravel setup at https://store.foodexeg.com

1. Provision PHP **8.3** with pdo_mysql, mbstring, openssl, zip, tokenizer and required Laravel extensions; MySQL/MariaDB and preferably Redis; TLS certificate for `store.foodexeg.com`.
2. Extract `FOODEX-Laravel-Setup.zip` into a **new clean deployment directory**. It must contain sibling `VERSION` and `backend/` paths. Point the website document root to `backend/public`; enable HTTPS and URL rewriting.
3. Copy `backend/.env.production.example` to `backend/.env`. The package preconfigures `APP_URL=https://store.foodexeg.com` and relevant preview allowed origins. Supply a **new production-only database**, DB credentials, mail/Firebase/Redis settings and any required external keys **on the server**, never in Git or the distribution archive. Do not reuse the demo database.
4. Make `backend/storage` and `backend/bootstrap/cache` writable by the PHP runtime. Generate the APP_KEY through the installer or Laravel; never check it into the repository.
5. Open **https://store.foodexeg.com/install** and finish the installation wizard, create the initial Super Admin and verify it locks the installation endpoint. Admin UI is under `/admin`.
6. Install each of the three matching versioned APKs on test Android devices. Confirm network connectivity and login/order workflows against the newly installed **production** backend; setup does not automatically deploy the APKs or provision user accounts.
7. Configure the separate production Android keystore, Firebase projects and provider secrets before real store submission. The workflow intentionally fails if the three APKs do not contain the new origin, or contain an old origin.

## Non-goals and limitations

- This does not deploy or change any remote server; server access, DNS/TLS, database and production secrets remain deployment prerequisites.
- The workflow performs an isolated clean-install acceptance with disposable CI MySQL/Redis, not a test of the actual `store.foodexeg.com` server.
- Publishing a new canonical FOODEX version requires the repository's existing release contract and separate CI; this branch does not rewrite tags or GitHub Releases.
