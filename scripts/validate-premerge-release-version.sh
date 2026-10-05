#!/usr/bin/env bash
set -euo pipefail

base="${1:-}"
head="${2:-HEAD}"

if [[ -z "$base" ]]; then
  if git rev-parse --verify origin/main >/dev/null 2>&1; then
    base="$(git merge-base origin/main "$head")"
  else
    echo "Unable to resolve base ref for release-version validation." >&2
    exit 2
  fi
fi

current_version="$(tr -d '[:space:]' < VERSION)"
base_version="$(git show "$base:VERSION" 2>/dev/null | tr -d '[:space:]' || true)"
customer_identity="$(awk '/^version:/{print $2; exit}' apps/customer_app/pubspec.yaml | tr -d '"')"
driver_identity="$(awk '/^version:/{print $2; exit}' apps/driver_app/pubspec.yaml | tr -d '"')"

if [[ -z "$current_version" || -z "$base_version" ]]; then
  echo "VERSION must exist in both base and candidate." >&2
  exit 1
fi

if [[ "$customer_identity" != "$driver_identity" ]]; then
  echo "Customer and Driver mobile version identities must match before merge." >&2
  exit 1
fi

if [[ "${driver_identity%%+*}" != "$current_version" ]]; then
  echo "Mobile version identity must match VERSION before merge." >&2
  exit 1
fi

runtime_changed=false
if [[ "$head" == "WORKTREE" ]]; then
  changed="$(
    {
      git diff --name-only "$base"
      git ls-files --others --exclude-standard
    } | sort -u
  )"
else
  changed="$(git diff --name-only "$base" "$head")"
fi
if grep -Eq '^(backend/|apps/customer_app/|apps/driver_app/)' <<<"$changed"; then
  runtime_changed=true
fi

if [[ "$runtime_changed" == "true" && "$current_version" == "$base_version" ]]; then
  echo "Deployable FOODEX code changed without a VERSION bump." >&2
  echo "This would pass PR-only checks but fail FOODEX Trial Distribution after merge to main." >&2
  echo "Bump VERSION and keep Customer/Driver pubspec version identities synchronized before merge." >&2
  exit 1
fi

echo "Pre-merge release version contract passed (runtime_changed=$runtime_changed, base=$base_version, candidate=$current_version)."
