#!/usr/bin/env bash
set -euo pipefail
for app in customer_app driver_app; do
  pushd "apps/$app" >/dev/null
  flutter create --platforms=android,ios --project-name "foodex_${app}" .
  app_key="${app%_app}"
  python3 ../../scripts/configure-mobile-native.py --app "$app_key" --app-dir . --platform all
  flutter pub get
  flutter analyze
  flutter test
  popd >/dev/null
done
