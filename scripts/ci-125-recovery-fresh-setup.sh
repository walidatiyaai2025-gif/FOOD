#!/usr/bin/env bash
# Fresh-install smoke from the exact generated, self-contained Setup ZIP.
set -euo pipefail
repo_root="$(git rev-parse --show-toplevel)"
archive="$repo_root/dist/recovery/FOODEX-125-Recovery-Setup.zip"
test -s "$archive"
workspace="$(mktemp -d)"
trap 'rm -rf "$workspace"' EXIT
unzip -q "$archive" -d "$workspace"
test "$(tr -d '[:space:]' < "$workspace/VERSION")" = "1.0.125"
test -s "$workspace/backend/vendor/autoload.php"
test -s "$workspace/backend/public/index.php"
test -f "$workspace/backend/app/Domain/Installer/InstallerWorkflow.php"
test ! -e "$workspace/backend/.env"
php -r '$pdo = new PDO("mysql:host=127.0.0.1;port=3306", "root", "foodex_ci_only"); $pdo->exec("CREATE DATABASE IF NOT EXISTS foodex_recovery_fresh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'
cd "$workspace/backend"
cat > .env <<'ENV'
APP_NAME=FOODEX
APP_ENV=testing
APP_DEBUG=false
APP_URL=http://localhost
LOG_CHANNEL=stderr
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=foodex_recovery_fresh
DB_USERNAME=root
DB_PASSWORD=foodex_ci_only
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
ENV
php artisan key:generate --force
php artisan migrate --force --no-interaction
php artisan route:list --json > "$workspace/routes.json"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! \Illuminate\Support\Facades\Schema::hasTable("order_customer_service_discounts")) {
    throw new RuntimeException("Discount audit table missing after fresh Setup ZIP install");
}
'
python3 - "$workspace/routes.json" <<'PY'
import json,sys
routes=json.load(open(sys.argv[1]))
assert any(r.get("uri","").strip("/") == "install" and "GET" in r.get("method","") for r in routes), "Web Installer /install route missing"
assert any("install/step/" in r.get("uri","") and "POST" in r.get("method","") for r in routes), "Web Installer progression route missing"
PY
php artisan optimize:clear
echo "PASS: exact 125 Recovery Setup ZIP includes production vendor, runs fresh MySQL migrations and exposes Web Installer routes"
