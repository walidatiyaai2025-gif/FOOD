#!/usr/bin/env bash
# Acceptance: unpack the actual recovery ZIP onto a real v1.0.124 Laravel
# installation with the partially-created MySQL discount audit table.
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
test "$(tr -d '[:space:]' < "$repo_root/VERSION")" = "1.0.125"
package="$repo_root/dist/update/FOODEX-Update.zip"
test -s "$package"
workspace="$(mktemp -d)"
cleanup() {
  git -C "$repo_root" worktree remove --force "$workspace/foodex-124" >/dev/null 2>&1 || true
  rm -rf "$workspace"
}
trap cleanup EXIT

git -C "$repo_root" worktree add --detach "$workspace/foodex-124" v1.0.124
backend="$workspace/foodex-124/backend"
cd "$backend"

composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
cat > .env <<'ENV'
APP_NAME=FOODEX
APP_ENV=testing
APP_DEBUG=false
APP_URL=http://localhost
LOG_CHANNEL=stderr
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=foodex_recovery_125
DB_USERNAME=root
DB_PASSWORD=foodex_ci_only
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
ENV
php artisan key:generate --force
php artisan migrate --force --no-interaction

# Reproduce the actual failure state: v125 migration created the audit table
# but crashed before Laravel wrote the migration ledger row.
git -C "$repo_root" show v1.0.125:backend/database/migrations/2026_11_08_070000_create_order_customer_service_discounts.php > "$workspace/original-migration.php"
RECOVERY_ORIGINAL_MIGRATION="$workspace/original-migration.php" \
php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

$now = date('Y-m-d H:i:s');
$user = DB::table('users')->insertGetId([
    'name'=>'Recovery QA', 'email'=>'recovery-125@example.invalid',
    'password'=>password_hash('temporary-only', PASSWORD_BCRYPT),
    'created_at'=>$now, 'updated_at'=>$now,
]);
$type = DB::table('store_types')->insertGetId([
    'code'=>'recovery-qa', 'name'=>'Recovery QA',
    'created_at'=>$now, 'updated_at'=>$now,
]);
$store = DB::table('stores')->insertGetId([
    'store_type_id'=>$type, 'code'=>'recovery-qa', 'name'=>'Recovery QA',
    'created_at'=>$now, 'updated_at'=>$now,
]);
$customer = DB::table('customers')->insertGetId([
    'name'=>'Recovery QA', 'type'=>'b2b',
    'created_at'=>$now, 'updated_at'=>$now,
]);
$order = DB::table('orders')->insertGetId([
    'store_id'=>$store, 'customer_id'=>$customer,
    'order_number'=>'RECOVERY-125-CI', 'channel'=>'b2b',
    'status'=>'pending', 'created_at'=>$now, 'updated_at'=>$now,
]);
(require getenv('RECOVERY_ORIGINAL_MIGRATION'))->up();
if (! Schema::hasTable('order_customer_service_discounts')) {
    throw new RuntimeException('Partial v125 table was not created');
}
DB::table('order_customer_service_discounts')->insert([
    'order_id'=>$order, 'applied_by_user_id'=>$user,
    'idempotency_key'=>'6b676f27-84ec-4aba-8507-5e62d7b44f23',
    'revision'=>1, 'percentage'=>'5.000',
    'reason'=>'preserve discount audit across interrupted deployment',
    'prior_customer_service_discount'=>'0.000',
    'customer_service_discount'=>'5.000',
    'discount_total_before'=>'0.000',
    'discount_total_after'=>'5.000',
    'grand_total_before'=>'100.000',
    'grand_total_after'=>'95.000',
    'order_status'=>'pending',
    'created_at'=>$now, 'updated_at'=>$now,
]);
if (DB::table('migrations')->where('migration', '2026_11_08_070000_create_order_customer_service_discounts')->exists()) {
    throw new RuntimeException('This test must start with missing migration ledger entry');
}
file_put_contents(storage_path('app/recovery-125-uploads-marker.txt'), 'must survive');
PHP

before_env_hash="$(sha256sum .env | cut -d' ' -f1)"
before_marker_hash="$(sha256sum storage/app/recovery-125-uploads-marker.txt | cut -d' ' -f1)"
unzip -q -o "$package" -d "$workspace/foodex-124"
test "$(tr -d '[:space:]' < "$workspace/foodex-124/VERSION")" = 1.0.125
test "$before_env_hash" = "$(sha256sum .env | cut -d' ' -f1)"
test "$before_marker_hash" = "$(sha256sum storage/app/recovery-125-uploads-marker.txt | cut -d' ' -f1)"

php artisan optimize:clear
php artisan migrate --force --no-interaction
php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
if (! Schema::hasTable('order_customer_service_discounts')) {
    throw new RuntimeException('Recovered audit table missing');
}
$row = DB::table('order_customer_service_discounts')
    ->where('idempotency_key','6b676f27-84ec-4aba-8507-5e62d7b44f23')->first();
if (! $row || (string) $row->reason !== 'preserve discount audit across interrupted deployment') {
    throw new RuntimeException('Preexisting discount history was not preserved');
}
if (! DB::table('migrations')->where('migration', '2026_11_08_070000_create_order_customer_service_discounts')->exists()) {
    throw new RuntimeException('Recovery migration ledger was not recorded');
}
PHP

php artisan migrate --force --no-interaction
test "$before_env_hash" = "$(sha256sum .env | cut -d' ' -f1)"
test "$before_marker_hash" = "$(sha256sum storage/app/recovery-125-uploads-marker.txt | cut -d' ' -f1)"
echo "PASS: actual v124 -> recovery125 ZIP, interrupted MySQL migration, audit data, ledger, .env and uploads preserved"
