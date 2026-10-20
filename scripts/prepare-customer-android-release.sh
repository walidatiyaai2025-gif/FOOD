#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/apps/customer_app"
ANDROID="$APP/android"
BACKUP="$APP/android_v1_backup_$(date +%Y%m%d_%H%M%S)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

cd "$APP"

if [[ -f "$ANDROID/app/google-services.json" ]]; then
  cp "$ANDROID/app/google-services.json" "$TMP/google-services.json"
else
  echo "ERROR: android/app/google-services.json is missing."
  exit 1
fi

if [[ -f "$ANDROID/app/src/release/AndroidManifest.xml" ]]; then
  cp "$ANDROID/app/src/release/AndroidManifest.xml" "$TMP/release-manifest.xml"
fi

if [[ -d "$ANDROID" ]]; then
  mv "$ANDROID" "$BACKUP"
fi

flutter create --platforms=android .

cp "$TMP/google-services.json" "$ANDROID/app/google-services.json"
if [[ -f "$TMP/release-manifest.xml" ]]; then
  mkdir -p "$ANDROID/app/src/release"
  cp "$TMP/release-manifest.xml" "$ANDROID/app/src/release/AndroidManifest.xml"
fi

python3 - <<'PY'
from pathlib import Path
import re

android = Path("android")
package_id = "com.fiftysolution.foodex.customer"

app_kts = android / "app" / "build.gradle.kts"
app_groovy = android / "app" / "build.gradle"
settings_kts = android / "settings.gradle.kts"
settings_groovy = android / "settings.gradle"

if app_kts.exists():
    text = app_kts.read_text()
    if 'id("com.google.gms.google-services")' not in text:
        text = text.replace(
            'plugins {',
            'plugins {\n    id("com.google.gms.google-services")',
            1,
        )
    text = re.sub(r'namespace\s*=\s*"[^"]+"', f'namespace = "{package_id}"', text)
    text = re.sub(r'applicationId\s*=\s*"[^"]+"', f'applicationId = "{package_id}"', text)
    app_kts.write_text(text)
elif app_groovy.exists():
    text = app_groovy.read_text()
    if "id 'com.google.gms.google-services'" not in text and 'id("com.google.gms.google-services")' not in text:
        text = text.replace(
            'plugins {',
            "plugins {\n    id 'com.google.gms.google-services'",
            1,
        )
    text = re.sub(r"namespace\\s+['\"][^'\"]+['\"]", f"namespace '{package_id}'", text)
    text = re.sub(r"applicationId\\s+['\"][^'\"]+['\"]", f"applicationId '{package_id}'", text)
    app_groovy.write_text(text)
else:
    raise SystemExit("ERROR: Android app Gradle file was not generated.")

if settings_kts.exists():
    text = settings_kts.read_text()
    if 'id("com.google.gms.google-services")' not in text:
        text = text.replace(
            'plugins {',
            'plugins {\n    id("com.google.gms.google-services") version "4.5.0" apply false',
            1,
        )
    settings_kts.write_text(text)
elif settings_groovy.exists():
    text = settings_groovy.read_text()
    if "com.google.gms.google-services" not in text:
        text = text.replace(
            'plugins {',
            "plugins {\n    id 'com.google.gms.google-services' version '4.5.0' apply false",
            1,
        )
    settings_groovy.write_text(text)
else:
    raise SystemExit("ERROR: Android settings Gradle file was not generated.")

activities = list((android / "app" / "src" / "main").rglob("MainActivity.kt"))
activities += list((android / "app" / "src" / "main").rglob("MainActivity.java"))
if not activities:
    raise SystemExit("ERROR: MainActivity was not generated.")

for activity in activities:
    text = activity.read_text()
    text = re.sub(r'^package\s+[^\n;]+;?', f'package {package_id}', text, count=1, flags=re.M)
    activity.write_text(text)
PY

python3 - <<'PY'
import json
from pathlib import Path

data = json.loads(Path("android/app/google-services.json").read_text())
packages = {
    client.get("client_info", {})
          .get("android_client_info", {})
          .get("package_name")
    for client in data.get("client", [])
}
expected = "com.fiftysolution.foodex.customer"
if expected not in packages:
    raise SystemExit(
        f"ERROR: google-services.json does not contain the expected package {expected}. "
        f"Found: {sorted(p for p in packages if p)}"
    )
print(f"Firebase package OK: {expected}")
PY

flutter pub get

cd android
./gradlew :app:processReleaseGoogleServices

echo
echo "ANDROID/FIREBASE PREFLIGHT PASSED"
echo "Backup kept at: $BACKUP"
echo "You can now run:"
echo "  cd $APP"
echo "  flutter build apk --release --dart-define=FOODEX_API_BASE_URL=https://foodex.50sols.com --dart-define=FOODEX_PUSH_ENVIRONMENT=production"
