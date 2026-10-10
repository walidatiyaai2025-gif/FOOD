#!/usr/bin/env bash
set -euo pipefail

mode="fast"
case "${1:-}" in
  ""|"--fast") mode="fast" ;;
  "--ci-parity") mode="ci-parity" ;;
  "--full") mode="full" ;;
  *) echo "Usage: $0 [--fast|--ci-parity|--full]" >&2; exit 2 ;;
esac

root="$(git rev-parse --show-toplevel)"
cd "$root"

branch="$(git branch --show-current)"
if [[ "$branch" == "main" ]]; then
  echo "Preflight refuses implementation work directly on main." >&2
  exit 1
fi
python3 scripts/foodex-branch-policy.py validate "$branch"

if git rev-parse --verify origin/main >/dev/null 2>&1; then
  base="$(git merge-base origin/main HEAD)"
else
  base="$(git rev-parse HEAD^ 2>/dev/null || git rev-parse HEAD)"
fi

echo "FOODEX preflight mode=$mode branch=$branch base=$base head=$(git rev-parse HEAD)"

python3 scripts/ci-plan.py --validate
python3 scripts/ci-failure-registry.py --validate
python3 scripts/flutter-test-hermeticity.py

declare -A area
while IFS='=' read -r key value; do
  area["$key"]="$value"
done < <(python3 scripts/ci-plan.py --base "$base" --head WORKTREE --branch "$branch")

changed_files="$(
  {
    git diff --name-only "$base"
    git ls-files --others --exclude-standard
  } | sort -u
)"

bash ./scripts/validate-premerge-release-version.sh "$base" WORKTREE
python3 ./scripts/localization-quality-gate.py --base "$base" --head WORKTREE
python3 ./scripts/mobile-ux-contract-guard.py --base "$base" --head WORKTREE
python3 ./scripts/foodex-skill-router.py --base "$base" --head WORKTREE --strict
./scripts/validate-repo.sh

release_related=false
if [[ "$branch" == release/* ]] || grep -Eq '^(VERSION|CHANGELOG\.md|docs/release/|apps/(customer_app|driver_app|van_app)/(pubspec\.yaml|lib/app\.dart))' <<<"$changed_files"; then
  release_related=true
fi
if [[ "$release_related" == "true" ]]; then
  bash ./scripts/release-readiness.sh
fi

check_lock_pair() {
  local lock="$1"
  local manifest="$2"
  local label="$3"
  if grep -qx "$lock" <<<"$changed_files" && ! grep -qx "$manifest" <<<"$changed_files"; then
    echo "$label lockfile changed without its manifest; verify dependency drift before push." >&2
    exit 1
  fi
}
check_lock_pair backend/composer.lock backend/composer.json Backend
check_lock_pair apps/customer_app/pubspec.lock apps/customer_app/pubspec.yaml Customer
check_lock_pair apps/driver_app/pubspec.lock apps/driver_app/pubspec.yaml Driver
check_lock_pair apps/van_app/pubspec.lock apps/van_app/pubspec.yaml Van

mapfile -t changed_php < <(grep -E '^backend/.*\.php$' <<<"$changed_files" || true)

if [[ "${area[backend]:-false}" == "true" ]]; then
  command -v php >/dev/null || { echo "PHP is required for backend preflight." >&2; exit 1; }
  command -v composer >/dev/null || { echo "Composer is required for backend preflight." >&2; exit 1; }
  command -v node >/dev/null || { echo "Node is required for backend CI parity." >&2; exit 1; }
  command -v npx >/dev/null || { echo "npx is required for backend CI parity." >&2; exit 1; }

  for path in "${changed_php[@]}"; do
    [[ -f "$path" ]] && php -l "$path" >/dev/null
  done

  (
    cd backend
    composer validate --strict --no-check-lock
    [[ -d vendor ]] || composer install --no-interaction --prefer-dist --no-progress

    if [[ "$mode" != "fast" && "${#changed_php[@]}" -gt 0 ]]; then
      php_paths=()
      for path in "${changed_php[@]}"; do
        [[ -f "../$path" ]] && php_paths+=("${path#backend/}")
      done
      if (("${#php_paths[@]}" > 0)); then
        vendor/bin/pint "${php_paths[@]}"
      fi
    fi

    composer lint
    composer analyse
    REDOCLY_TELEMETRY=off REDOCLY_SUPPRESS_UPDATE_NOTICE=true npx --yes @redocly/cli@2.59.0 lint ../docs/api/openapi.yaml

    if [[ "$mode" != "fast" ]]; then
      cp .env.example .env
      php artisan key:generate
      mkdir -p database
      touch database/database.sqlite
      DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite php artisan migrate:fresh --force
      DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite php artisan db:seed --force

      mapfile -t focused < <(python3 ../scripts/ci-plan.py --base "$base" --head WORKTREE --branch "$branch" --focused backend)
      if (("${#focused[@]}" > 0)); then
        echo "Running focused backend tests first: ${focused[*]}"
        vendor/bin/phpunit "${focused[@]}"
      fi

      composer test
      composer audit
    fi
  )
fi

run_flutter() {
  local app="$1"
  local dir="apps/${app}_app"
  command -v flutter >/dev/null || { echo "Flutter is required for $app preflight." >&2; exit 1; }

  mapfile -t dart_files < <(grep -E "^apps/${app}_app/(lib|test)/.*\.dart$" <<<"$changed_files" | sed "s#^apps/${app}_app/##" || true)

  (
    cd "$dir"
    flutter pub get

    if [[ "$mode" != "fast" && "${#dart_files[@]}" -gt 0 ]]; then
      dart format "${dart_files[@]}"
      dart format --output=none --set-exit-if-changed "${dart_files[@]}"
    fi

    flutter analyze

    mapfile -t focused < <(python3 ../../scripts/ci-plan.py --base "$base" --head WORKTREE --branch "$branch" --focused "$app")
    if (("${#focused[@]}" > 0)); then
      echo "Running focused $app tests first: ${focused[*]}"
      flutter test "${focused[@]}"
    fi

    mapfile -t tests < <(find test -type f -name '*_test.dart' ! -name 'screenshot_evidence_test.dart' ! -name 'top_products_visual_trial_test.dart' -print 2>/dev/null || true)
    if (("${#tests[@]}" > 0)); then
      flutter test "${tests[@]}"
    fi

    if [[ "$mode" != "fast" ]]; then
      flutter build apk --release --dart-define=FOODEX_API_BASE_URL=https://foodex.50sols.com
      python3 ../../scripts/verify-mobile-runtime-endpoint.py build/app/outputs/flutter-apk/app-release.apk

      if [[ "$app" == "customer" && -f lib/preview_main.dart ]]; then
        flutter build web --release --target=lib/preview_main.dart \
          --dart-define=FOODEX_API_BASE_URL=https://foodex.50sols.com \
          --dart-define=FOODEX_PREVIEW_PARENT_ORIGIN=https://foodex.50sols.com \
          --dart-define=FOODEX_PREVIEW_CONTRACT_VERSION=shared-flutter-v1
      fi
    fi
  )
}

[[ "${area[customer]:-false}" == "true" ]] && run_flutter customer
[[ "${area[driver]:-false}" == "true" ]] && run_flutter driver
[[ "${area[van]:-false}" == "true" ]] && run_flutter van

git diff --check "$base"

if [[ "$mode" == "full" ]] && grep -Eq '^backend/database/migrations/' <<<"$changed_files"; then
  if command -v docker >/dev/null 2>&1; then
    echo "Migration change detected; local Docker is available. Run backend production-service acceptance if your environment exposes the repository CI service stack."
  else
    echo "WARNING: migration changed but Docker is unavailable; GitHub MySQL/Redis acceptance remains authoritative." >&2
  fi
fi

echo "FOODEX $mode preflight passed for all affected areas."
