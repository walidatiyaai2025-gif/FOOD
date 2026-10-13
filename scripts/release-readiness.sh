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

customer_freeze_contract="docs/release/FOODEX_1_0_77_CUSTOMER_FREEZE.json"
production_hold_file="docs/release/FOODEX_1_0_77_PRODUCTION_HOLD.md"
customer_version="$(awk '/^version:/ {print $2; exit}' apps/customer_app/pubspec.yaml)"
driver_version="$(awk '/^version:/ {print $2; exit}' apps/driver_app/pubspec.yaml)"
van_version="$(awk '/^version:/ {print $2; exit}' apps/van_app/pubspec.yaml)"

if [[ "$release_version" == "1.0.77" ]]; then
  python3 scripts/validate_customer_freeze_contract.py \
    --contract "$customer_freeze_contract" \
    --candidate WORKTREE \
    --metadata-out artifacts/customer-frozen-lineage.json
  frozen_customer_identity="$(python3 -c 'import json; print(json.load(open("docs/release/FOODEX_1_0_77_CUSTOMER_FREEZE.json"))["frozen_customer"]["source_identity"])')"
  if [[ "$customer_version" != "$frozen_customer_identity" ]]; then
    echo "::error::Frozen Customer identity mismatch: contract=$frozen_customer_identity source=$customer_version"
    exit 1
  fi
  if [[ -f "$production_hold_file" ]]; then
    echo "FOODEX 1.0.77 production hold is active; Customer $customer_version is frozen and carried forward unchanged."
    echo "Final publication remains blocked until #1380 authorizes removal of the hold."
    exit 0
  fi
  if [[ "$driver_version" != "$van_version" ]]; then
    echo "::error::Driver/Van release identity mismatch: Driver=$driver_version Van=$van_version"
    exit 1
  fi
  mobile_release_version="${driver_version%%+*}"
else
  if [[ "$customer_version" != "$driver_version" || "$customer_version" != "$van_version" ]]; then
    echo "::error::Mobile release identity mismatch: Customer=$customer_version Driver=$driver_version Van=$van_version"
    exit 1
  fi
  mobile_release_version="${customer_version%%+*}"
fi
[[ "$mobile_release_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]

if [[ "$release_version" != "$mobile_release_version" ]]; then
  echo "::error::VERSION mismatch: repository=$release_version mobile=$mobile_release_version"
  exit 1
fi

customer_ui_version="$(awk -F"'" '/^const customerAppVersion =/ {print $2; exit}' apps/customer_app/lib/core/config/customer_app_release.dart)"
driver_ui_version="$(awk -F"'" '/static const _appVersion =/ {print $2; exit}' apps/driver_app/lib/app.dart)"
if [[ "$release_version" == "1.0.77" ]]; then
  test "$customer_ui_version" = "${customer_version%%+*}"
else
  test "$customer_ui_version" = "$release_version"
fi
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

if [[ "$release_version" == "1.0.77" ]]; then
  echo "Release identity $release_version is ready with frozen Customer $customer_version carried forward and Driver/Van $driver_version."
else
  echo "Release identity $release_version is synchronized across repository and mobile artifacts."
fi
echo 'Release readiness evidence is structurally complete.'
