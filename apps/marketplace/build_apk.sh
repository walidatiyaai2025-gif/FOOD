#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
command -v flutter >/dev/null || { echo "Flutter is not installed or not in PATH" >&2; exit 1; }
backup="$(mktemp -d)"
cp -R lib "$backup/lib"
cp pubspec.yaml analysis_options.yaml "$backup/"
flutter create --platforms=android --org com.foodex.mockup --project-name foodex_marketplace_mockup .
rm -rf lib
cp -R "$backup/lib" ./lib
cp "$backup/pubspec.yaml" "$backup/analysis_options.yaml" .
manifest="android/app/src/main/AndroidManifest.xml"
if ! grep -q 'android.permission.INTERNET' "$manifest"; then
  python3 - "$manifest" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
s=s.replace('<manifest xmlns:android="http://schemas.android.com/apk/res/android">', '<manifest xmlns:android="http://schemas.android.com/apk/res/android">\n    <uses-permission android:name="android.permission.INTERNET" />', 1)
p.write_text(s)
PY
fi
flutter pub get
flutter analyze
flutter build apk --release
cp build/app/outputs/flutter-apk/app-release.apk Foodx-Marketplace-Mockup.apk
rm -rf "$backup"
echo "APK: $(pwd)/Foodx-Marketplace-Mockup.apk"
