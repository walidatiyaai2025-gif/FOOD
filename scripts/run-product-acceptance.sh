#!/usr/bin/env bash
set -euo pipefail

pushd backend >/dev/null
php artisan test --testsuite=Feature
popd >/dev/null

pushd apps/customer_app >/dev/null
flutter test
popd >/dev/null

pushd apps/driver_app >/dev/null
flutter test
popd >/dev/null
