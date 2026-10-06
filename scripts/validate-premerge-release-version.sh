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

version_changed=false
if [[ "$current_version" != "$base_version" ]]; then
  version_changed=true
fi

branch_name="${GITHUB_HEAD_REF:-${GITHUB_REF_NAME:-}}"
release_branch=false
if [[ "$branch_name" == release/* ]]; then
  release_branch=true
fi

# Normal feature/bug PRs are allowed to change deployable code without publishing
# a new release. Release synchronization becomes mandatory only when release intent
# exists (VERSION changed) or when work is explicitly happening on a release/* branch.
if [[ "$version_changed" != "true" && "$release_branch" != "true" ]]; then
  echo "No release intent detected; VERSION may remain $current_version for normal feature/bug work."
  exit 0
fi

if [[ "$release_branch" == "true" && "$version_changed" != "true" ]]; then
  echo "Release branch '$branch_name' must bump VERSION relative to base ($base_version)." >&2
  exit 1
fi

if [[ "$customer_identity" != "$driver_identity" ]]; then
  echo "Customer and Driver mobile version identities must match for release work." >&2
  exit 1
fi

if [[ "${driver_identity%%+*}" != "$current_version" ]]; then
  echo "Mobile version identity must match VERSION for release work." >&2
  exit 1
fi

echo "Pre-merge release version contract passed (release_intent=true, base=$base_version, candidate=$current_version)."
