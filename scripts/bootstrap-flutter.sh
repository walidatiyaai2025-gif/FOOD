#!/usr/bin/env bash
set -euo pipefail
for app in customer_app driver_app; do
  pushd "apps/$app" >/dev/null
  flutter create --platforms=android,ios --project-name "foodex_${app}" .
  flutter pub get
  flutter analyze
  flutter test
  popd >/dev/null
done
