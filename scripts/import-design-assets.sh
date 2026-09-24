#!/usr/bin/env bash
set -euo pipefail
archive="${1:?Usage: $0 /path/to/Foodex_Design_Reference_Laravel_Flutter.zip}"
expected="bfc481ead664c3b683fb84be45fabc3108394154b9326d27ef3c58cee5171f45"
actual="$(sha256sum "$archive" | awk '{print $1}')"
[[ "$actual" == "$expected" ]] || { echo "Design archive SHA-256 mismatch"; exit 1; }
rm -rf docs/design-reference/assets
mkdir -p docs/design-reference/assets
unzip -q "$archive" -d docs/design-reference/assets
echo "Design reference materialized and verified."
