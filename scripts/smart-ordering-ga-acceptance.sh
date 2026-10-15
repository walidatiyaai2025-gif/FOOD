#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"

cd "$BACKEND"

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="$BACKEND/database/database.sqlite"
export CACHE_STORE=array
export SESSION_DRIVER=array
export QUEUE_CONNECTION=sync

cp -n .env.example .env || true
php artisan key:generate --force >/dev/null
mkdir -p database
: > database/database.sqlite

php artisan migrate:fresh --force

php artisan test   tests/Feature/SmartOrderingProgramCertificationTest.php   tests/Feature/SmartOrderingFoundationTest.php   tests/Feature/SmartOrderingMenuDomainTest.php   tests/Feature/SmartOrderingStorefrontTest.php   tests/Feature/SmartOrderingCoreTest.php   tests/Feature/SmartOrderingQrTest.php   tests/Feature/SmartOrderingWhatsAppBridgeTest.php   tests/Feature/SmartOrderingCustomerGrowthTest.php   tests/Feature/SmartOrderingAnalyticsTest.php   tests/Feature/SmartOrderingSupplyIntelligenceTest.php

php artisan route:list --path=smart-ordering --except-vendor >/tmp/smart-ordering-routes.txt
test -s /tmp/smart-ordering-routes.txt
grep -F 'smart-ordering' /tmp/smart-ordering-routes.txt >/dev/null

if grep -R -n -E '^(<<<<<<<|=======|>>>>>>>)' app routes tests config database resources; then
  echo "::error::Merge conflict marker found in backend source."
  exit 1
fi

echo "Smart Ordering GA acceptance: PASS"
