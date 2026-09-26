#!/usr/bin/env bash
set -euo pipefail

output_dir="${1:-Release/Updates}"
minimum_version="${2:-}"
target_version="${3:-$(tr -d '[:space:]' < VERSION)}"
base_ref="${4:-HEAD^}"

mkdir -p "$output_dir"

if [[ -z "$target_version" ]]; then
  echo "VERSION is empty." >&2
  exit 1
fi

if [[ -z "$minimum_version" ]]; then
  minimum_version="$target_version"
fi

zip_path="$output_dir/FOODEX-Update.zip"
manifest_path="$output_dir/FOODEX-Update.json"
rm -f "$zip_path" "$manifest_path"

write_manifest() {
  local available="$1"
  local sha="$2"
  local migrations="$3"
  local full_redeploy="$4"
  local reason="$5"
  local notes="$6"

  AVAILABLE="$available"   PACKAGE_SHA="$sha"   CONTAINS_MIGRATIONS="$migrations"   REQUIRES_FULL_REDEPLOY="$full_redeploy"   REASON="$reason"   RELEASE_NOTES="$notes"   MINIMUM_VERSION="$minimum_version"   TARGET_VERSION="$target_version"   python3 - <<'PY'
import json
import os
from pathlib import Path

payload = {
    "schema_version": 1,
    "available": os.environ["AVAILABLE"] == "true",
    "package": "FOODEX-Update.zip" if os.environ["AVAILABLE"] == "true" else None,
    "target_version": os.environ["TARGET_VERSION"],
    "minimum_current_version": os.environ["MINIMUM_VERSION"],
    "sha256": os.environ["PACKAGE_SHA"] or None,
    "contains_migrations": os.environ["CONTAINS_MIGRATIONS"] == "true",
    "requires_full_redeploy": os.environ["REQUIRES_FULL_REDEPLOY"] == "true",
    "reason": os.environ["REASON"] or None,
    "release_notes": os.environ["RELEASE_NOTES"],
    "dashboard_fields": {
        "target_version": os.environ["TARGET_VERSION"],
        "minimum_current_version": os.environ["MINIMUM_VERSION"],
        "sha256": os.environ["PACKAGE_SHA"] or "",
        "contains_migrations": os.environ["CONTAINS_MIGRATIONS"] == "true",
        "release_notes": os.environ["RELEASE_NOTES"],
    },
}
Path("Release/Updates/FOODEX-Update.json").write_text(
    json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
    encoding="utf-8",
)
PY
}

notes="$(git log -1 --pretty=%s 2>/dev/null || printf 'FOODEX update %s' "$target_version")"

if [[ "$target_version" == "$minimum_version" ]]; then
  write_manifest false "" false false "No newer server update exists yet. This is the first-install baseline." "$notes"
  echo "No dashboard update ZIP created because target and current versions are both $target_version."
  exit 0
fi

lowest="$(printf '%s\n%s\n' "$minimum_version" "$target_version" | sort -V | head -n1)"
if [[ "$lowest" != "$minimum_version" ]]; then
  echo "Target version $target_version is not newer than minimum/current version $minimum_version." >&2
  exit 1
fi

if git diff --name-only "$base_ref" HEAD -- backend/composer.json backend/composer.lock 2>/dev/null | grep -q .; then
  write_manifest false "" false true "Composer dependencies changed. Dashboard Update Center intentionally cannot replace vendor/; deploy the new Laravel setup package instead." "$notes"
  echo "Dashboard update ZIP skipped because Composer dependencies changed."
  exit 0
fi

contains_migrations=false
if git diff --name-only "$base_ref" HEAD -- backend/database/migrations 2>/dev/null | grep -q .; then
  contains_migrations=true
fi

release_paths=(
  VERSION
  backend/app
  backend/artisan
  backend/bootstrap
  backend/config
  backend/database
  backend/lang
  backend/public
  backend/resources
  backend/routes
)

git archive --format=zip --output="$zip_path" HEAD "${release_paths[@]}"

sha="$(sha256sum "$zip_path" | awk '{print $1}')"
write_manifest true "$sha" "$contains_migrations" false "" "$notes"

echo "Created dashboard update package: $zip_path"
echo "SHA-256: $sha"
