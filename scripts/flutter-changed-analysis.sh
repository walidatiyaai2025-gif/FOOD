#!/usr/bin/env bash
set -euo pipefail

if (( "$#" < 2 || "$#" > 3 )); then
  echo "Usage: $0 <customer|driver|van> <base-ref> [head-ref|WORKTREE]" >&2
  exit 2
fi

app="$1"
base="$2"
head="${3:-WORKTREE}"

case "$app" in
  customer|driver|van) ;;
  *) echo "Unsupported Flutter app: $app" >&2; exit 2 ;;
esac

root="$(git rev-parse --show-toplevel)"
prefix="apps/${app}_app"

collect_changed() {
  if [[ "$head" == "WORKTREE" ]]; then
    {
      git -C "$root" diff --name-only "$base" -- "$prefix/lib" "$prefix/test"
      git -C "$root" ls-files --others --exclude-standard -- "$prefix/lib" "$prefix/test"
    } | sort -u
  else
    git -C "$root" diff --name-only "$base" "$head" -- "$prefix/lib" "$prefix/test" | sort -u
  fi
}

mapfile -t files < <(
  collect_changed \
    | grep -E "^${prefix}/(lib|test)/.*\.dart$" \
    | sed "s#^${prefix}/##" \
    || true
)

if (("${#files[@]}" == 0)); then
  echo "No changed Dart files for $app strict analysis."
  exit 0
fi

command -v dart >/dev/null || { echo "Dart is required for strict $app analysis." >&2; exit 1; }

echo "Strict changed-file Flutter analysis: app=$app files=${#files[@]} base=$base head=$head"

if ! (
  cd "$root/$prefix"
  dart format --output=none --set-exit-if-changed "${files[@]}"
); then
  echo "=== dart format diff for changed $app files ===" >&2
  git -C "$root" diff -- "$prefix/lib" "$prefix/test" >&2 || true
  echo "=== end dart format diff ===" >&2
  echo "Changed $app Dart files are not canonically formatted. Run dart format before push." >&2
  exit 1
fi

suppression_re='//[[:space:]]*ignore(_for_file)?:.*(use_build_context_synchronously|cancel_subscriptions|close_sinks|unawaited_futures|avoid_void_async)'
if [[ "$head" == "WORKTREE" ]]; then
  diff_text="$(git -C "$root" diff --unified=0 "$base" -- "$prefix/lib" "$prefix/test")"
else
  diff_text="$(git -C "$root" diff --unified=0 "$base" "$head" -- "$prefix/lib" "$prefix/test")"
fi

if grep -E "^\+[^+].*${suppression_re}" <<<"$diff_text" >/dev/null; then
  echo "Do not add suppressions for FOODEX Flutter lifecycle/async safety lints; fix the cause or document an approved exception in the task contract." >&2
  exit 1
fi

if [[ "$head" == "WORKTREE" ]]; then
  while IFS= read -r untracked; do
    [[ -n "$untracked" && -f "$root/$untracked" ]] || continue
    if grep -En "$suppression_re" "$root/$untracked" >/dev/null; then
      echo "Do not add suppressions for FOODEX Flutter lifecycle/async safety lints in new file: $untracked" >&2
      exit 1
    fi
  done < <(git -C "$root" ls-files --others --exclude-standard -- "$prefix/lib" "$prefix/test" | grep -E "\.dart$" || true)
fi

(
  cd "$root/$prefix"
  for file in "${files[@]}"; do
    [[ -f "$file" ]] || continue
    echo "dart analyze --fatal-infos $file"
    dart analyze --fatal-infos "$file"
  done
)

echo "Strict changed-file Flutter analysis passed for $app."
