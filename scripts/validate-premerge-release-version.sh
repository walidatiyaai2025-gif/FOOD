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
van_identity="$(awk '/^version:/{print $2; exit}' apps/van_app/pubspec.yaml | tr -d '"')"

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

production_hold_file="docs/release/FOODEX_1_0_77_PRODUCTION_HOLD.md"
hold_retirement_promotion=false
if [[ "$current_version" == "1.0.77" ]]; then
  python3 scripts/validate_customer_freeze_contract.py --candidate "$head"
  frozen_customer_identity="$(python3 -c 'import json; print(json.load(open("docs/release/FOODEX_1_0_77_CUSTOMER_FREEZE.json"))["frozen_customer"]["source_identity"])')"
  if [[ "$customer_identity" != "$frozen_customer_identity" ]]; then
    echo "Frozen Customer identity must remain $frozen_customer_identity; got $customer_identity." >&2
    exit 1
  fi
  if [[ -f "$production_hold_file" ]]; then
    echo "Pre-merge release hold contract passed: candidate=1.0.77, Customer $customer_identity carried forward unchanged."
    exit 0
  fi
  # FOODEX 1.0.77 advanced VERSION on main while publication was intentionally held.
  # The terminal promotion is therefore identified by retiring that exact hold,
  # not by inventing a second version bump. Keep this exception narrow and
  # machine-verifiable so normal release/* branches still require VERSION drift.
  if [[ "$base_version" == "1.0.77" ]] \
    && git cat-file -e "$base:$production_hold_file" 2>/dev/null; then
    hold_retirement_promotion=true
  fi
fi
branch_name="${GITHUB_HEAD_REF:-${GITHUB_REF_NAME:-}}"
base_branch_name="${GITHUB_BASE_REF:-}"
release_branch=false
dashboard_update_release=false
if [[ "$branch_name" == release/* ]]; then
  release_branch=true
fi
if [[ "$branch_name" == release/*-update || "$base_branch_name" == release/*-update ]]; then
  dashboard_update_release=true
fi

# Normal feature/bug PRs are allowed to change deployable code without publishing
# a new release. Release synchronization becomes mandatory only when release intent
# exists (VERSION changed) or when work is explicitly happening on a release/* branch.
if [[ "$version_changed" != "true" && "$release_branch" != "true" ]]; then
  echo "No release intent detected; VERSION may remain $current_version for normal feature/bug work."
  exit 0
fi

if [[ "$release_branch" == "true" && "$version_changed" != "true" ]]; then
  # A published release's Dashboard artifact sync is not a second version
  # promotion. This narrow five-path lane is independently certified by
  # release-sync-gate.yml; all other release branches still bump VERSION.
  if [[ "$branch_name" =~ ^release/([0-9]+\.[0-9]+\.[0-9]+)-dashboard-update-sync$ ]] \
    && [[ "${BASH_REMATCH[1]}" == "$current_version" ]]; then
    expected_paths="$(printf '%s\n' \
      'Release/Updates/FOODEX-Update.files.txt' \
      'Release/Updates/FOODEX-Update.json' \
      'Release/Updates/FOODEX-Update.sha256.txt' \
      'Release/Updates/FOODEX-Update.zip' \
      'docs/release/RELEASE_REGISTRY.json' | LC_ALL=C sort)"
    actual_paths="$(printf '%s\n' "$changed" | sed '/^$/d' | LC_ALL=C sort -u)"
    if [[ "$actual_paths" != "$expected_paths" ]]; then
      echo "Dashboard release sync must change exactly the five certified generated paths." >&2
      exit 1
    fi
    if [[ "${customer_identity%%+*}" != "$current_version" \
      || "${driver_identity%%+*}" != "$current_version" \
      || "${van_identity%%+*}" != "$current_version" ]]; then
      echo "Dashboard release sync must preserve synchronized mobile VERSION identities." >&2
      exit 1
    fi
    python3 - "$base" "$current_version" <<'PY'
import hashlib
import json
import pathlib
import subprocess
import sys

base, version = sys.argv[1:]
root = pathlib.Path(".")
read = lambda path: json.loads((root / path).read_text(encoding="utf-8"))
base_registry = json.loads(subprocess.check_output(
    ["git", "show", f"{base}:docs/release/RELEASE_REGISTRY.json"], text=True
))
registry = read("docs/release/RELEASE_REGISTRY.json")
manifest = read("Release/Updates/FOODEX-Update.json")
releases = registry.get("releases", [])
previous = base_registry.get("releases", [])
if (registry.get("current_version") != version
        or len(releases) != len(previous) + 1
        or releases[:-1] != previous
        or releases[-1].get("version") != version
        or releases[-1].get("state") != "published"
        or releases[-1].get("registration") != "generated-release"
        or base_registry.get("current_version") == version):
    raise SystemExit("Published release sync must append precisely one immutable registered version.")
if (manifest.get("available") is not True
        or manifest.get("target_version") != version
        or manifest.get("minimum_current_version") != base_registry.get("current_version")
        or manifest.get("package") != "FOODEX-Update.zip"
        or manifest.get("delta", {}).get("files_manifest") != "FOODEX-Update.files.txt"):
    raise SystemExit("Dashboard update manifest must match current and prior registered release.")
source = str(manifest.get("source_commit", ""))
if len(source) != 40 or any(c not in "0123456789abcdef" for c in source):
    raise SystemExit("Dashboard update source_commit must be a full commit SHA.")
if subprocess.run(["git", "merge-base", "--is-ancestor", source, base], check=False).returncode:
    raise SystemExit("Dashboard update source must be an ancestor of the protected base.")
digest = hashlib.sha256((root / "Release/Updates/FOODEX-Update.zip").read_bytes()).hexdigest()
checksum = (root / "Release/Updates/FOODEX-Update.sha256.txt").read_text(
    encoding="utf-8"
).split()
entry = releases[-1]
dashboard = manifest.get("dashboard_fields", {})
if (manifest.get("sha256") != digest
        or not checksum or checksum[0] != digest
        or entry.get("dashboard_update_sha256") != digest
        or dashboard.get("target_version") != version
        or dashboard.get("sha256") != digest):
    raise SystemExit("Published Dashboard package, registry and manifest hashes must match.")
print(f"Verified published {version} Dashboard artifact sync: {digest}")
PY
    echo "Verified published Dashboard artifact sync accepted without a second VERSION bump."
    exit 0
  fi
  if [[ "$branch_name" == "release/1.0.77-production-program" && "$hold_retirement_promotion" == "true" ]]; then
    echo "Certified 1.0.77 hold-retirement promotion accepted: base and candidate VERSION remain 1.0.77 while the production HOLD is removed."
  else
    echo "Release branch '$branch_name' must bump VERSION relative to base ($base_version)." >&2
    exit 1
  fi
fi

if [[ "$dashboard_update_release" == "true" ]]; then
  if [[ "$customer_identity" != "$driver_identity" || "$driver_identity" != "$van_identity" ]]; then
    echo "Dashboard-only release requires unchanged, mutually synchronized mobile identities." >&2
    exit 1
  fi

  mobile_changes="$(printf '%s\n' "$changed" | grep -E '^apps/(customer_app|driver_app|van_app)/' || true)"
  if [[ -n "$mobile_changes" ]]; then
    echo "Dashboard-only release must not change mobile app sources:" >&2
    printf '%s\n' "$mobile_changes" >&2
    exit 1
  fi

  echo "Pre-merge dashboard-only release contract passed (base=$base_version, candidate=$current_version, mobile=$customer_identity unchanged)."
  exit 0
fi

if [[ "$current_version" != "1.0.77" && "$customer_identity" != "$driver_identity" ]]; then
  echo "Customer and Driver mobile version identities must match for non-frozen release work." >&2
  exit 1
fi

if [[ "$driver_identity" != "$van_identity" ]]; then
  echo "Driver and Van mobile version identities must match for release work." >&2
  exit 1
fi

if [[ "${driver_identity%%+*}" != "$current_version" ]]; then
  echo "Mobile version identity must match VERSION for release work." >&2
  exit 1
fi

if [[ "$current_version" == "1.0.77" ]]; then
  echo "Pre-merge release version contract passed (release_intent=true, candidate=1.0.77, Customer=$customer_identity carried_forward, Driver/Van=$driver_identity)."
else
  echo "Pre-merge release version contract passed (release_intent=true, base=$base_version, candidate=$current_version)."
fi
