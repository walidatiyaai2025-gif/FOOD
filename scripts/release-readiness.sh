#!/usr/bin/env bash
set -euo pipefail

test -f VERSION
test -f docs/api/openapi.yaml
test -f docs/installer/INSTALLER_ARCHITECTURE.md
test -f docs/updater/UPDATE_SYSTEM.md
test -f backend/docs/APP_VERSION_POLICY.md
test -f docs/architecture/SECURITY_BASELINE.md
test -f docs/release/RELEASE_CHECKLIST.md
test -f docs/release/RELEASE_NOTES.md
test -f docs/release/PRODUCTION_CONFIGURATION.md
test -f backend/.env.production.example

test -f backend/app/Http/Controllers/Api/V1/HealthController.php
grep -q "Route::get('/health'" backend/routes/api.php
grep -Eq '^  /(v1/)?app-version:' docs/api/openapi.yaml
grep -q 'Clean install' docs/release/RELEASE_CHECKLIST.md
grep -q 'Rollback' docs/release/RELEASE_CHECKLIST.md
grep -q 'Android' docs/release/RELEASE_CHECKLIST.md
grep -q 'iOS' docs/release/RELEASE_CHECKLIST.md

release_version="$(tr -d '\r\n' < VERSION)"
[[ "$release_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]

customer_freeze_sha="4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d"
customer_freeze_tree_sha="7e5828423d2b747afcd607a643c853495024328c"
production_hold_file="docs/release/FOODEX_1_0_77_PRODUCTION_HOLD.md"
if [[ "$release_version" == "1.0.77" && -f "$production_hold_file" ]]; then
  current_customer_tree="$(git rev-parse HEAD:apps/customer_app 2>/dev/null || true)"
  if [[ "$current_customer_tree" != "$customer_freeze_tree_sha" ]]; then
    echo "::error::FOODEX 1.0.77 production hold requires apps/customer_app/** tree $customer_freeze_tree_sha from baseline $customer_freeze_sha; current tree is ${current_customer_tree:-missing}."
    exit 1
  fi
  if ! git diff --quiet -- apps/customer_app || ! git diff --cached --quiet -- apps/customer_app || [[ -n "$(git ls-files --others --exclude-standard apps/customer_app)" ]]; then
    echo "::error::FOODEX 1.0.77 production hold forbids Customer worktree/index/untracked changes."
    exit 1
  fi
  echo "FOODEX 1.0.77 production hold is active; Customer tree is frozen and unchanged ($customer_freeze_tree_sha)."
  echo "Final synchronized publication remains blocked until #1380 replaces the hold with the frozen-lineage release contract."
  exit 0
fi
customer_version="$(awk '/^version:/ {print $2; exit}' apps/customer_app/pubspec.yaml)"
driver_version="$(awk '/^version:/ {print $2; exit}' apps/driver_app/pubspec.yaml)"
van_version="$(awk '/^version:/ {print $2; exit}' apps/van_app/pubspec.yaml)"
if [[ "$customer_version" != "$driver_version" || "$customer_version" != "$van_version" ]]; then
  echo "::error::Mobile release identity mismatch: Customer=$customer_version Driver=$driver_version Van=$van_version"
  exit 1
fi
mobile_release_version="${customer_version%%+*}"
[[ "$mobile_release_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]

if [[ "$release_version" != "$mobile_release_version" ]]; then
  echo "::error::VERSION mismatch: repository=$release_version mobile=$mobile_release_version"
  exit 1
fi

customer_ui_version="$(awk -F"'" '/^const customerAppVersion =/ {print $2; exit}' apps/customer_app/lib/core/config/customer_app_release.dart)"
driver_ui_version="$(awk -F"'" '/static const _appVersion =/ {print $2; exit}' apps/driver_app/lib/app.dart)"
test "$customer_ui_version" = "$release_version"
test "$driver_ui_version" = "$release_version"

grep -Eq "^# FOODEX ${release_version}.*Release Notes$" docs/release/RELEASE_NOTES.md
grep -Fq "## $mobile_release_version -" CHANGELOG.md
grep -Fq "VERSION and release notes identify the exact release being promoted. Evidence: #178" docs/release/RELEASE_CHECKLIST.md

production_origin="$(awk -F"'" '/defaultValue:/ {print $2; exit}' apps/customer_app/lib/core/config/foodex_environment.dart)"
test -n "$production_origin"
grep -Fq "APP_URL=$production_origin" backend/.env.production.example
grep -Eq '^DB_DATABASE=[A-Za-z0-9_]+' backend/.env.production.example
grep -Eq '^DB_USERNAME=[A-Za-z0-9_]+' backend/.env.production.example
grep -Fq "defaultValue: '$production_origin'" apps/driver_app/lib/core/config/foodex_environment.dart
! grep -R -Fq "foodex-validation.invalid" .github/workflows/customer-app-ci.yml .github/workflows/driver-app-ci.yml

echo "Release identity $release_version is synchronized across repository and mobile artifacts."
echo 'Release readiness evidence is structurally complete.'
