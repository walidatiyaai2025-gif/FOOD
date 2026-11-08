#!/usr/bin/env bash
# Acceptance: apply the exact generated 1.0.127 Dashboard Update ZIP to the
# immutable 1.0.125 installation, including a partially registered owner table,
# a missing discount permission and live order/audit/upload data.
set -Eeuo pipefail

repo_root="$(git rev-parse --show-toplevel)"
test "$(tr -d '[:space:]' < "$repo_root/VERSION")" = "1.0.127"
package="$repo_root/dist/update/FOODEX-Update.zip"
test -s "$package"
python3 - "$package" "$repo_root/dist/update/FOODEX-Update.json" <<'PY'
import hashlib, json, pathlib, sys
package = pathlib.Path(sys.argv[1])
manifest = json.loads(pathlib.Path(sys.argv[2]).read_text())
assert manifest["target_version"] == "1.0.127"
assert manifest["minimum_current_version"] == "1.0.125"
assert manifest["sha256"] == hashlib.sha256(package.read_bytes()).hexdigest()
PY
# Critical recovery files must be present even if unchanged since v125.
for required in   backend/app/Providers/AppServiceProvider.php   backend/app/Services/SystemInspectorRecorder.php   backend/app/Services/BuiltInJobRoleCatalog.php   backend/database/seeders/BuiltInJobRoleSeeder.php   backend/config/permissions.php   backend/database/migrations/2026_11_08_071000_create_platform_owner_registry.php   backend/database/migrations/2026_11_08_072000_restore_customer_service_discount_permission.php   backend/resources/views/admin/order-operations.blade.php
do
  unzip -Z1 "$package" | grep -Fx "$required" > /dev/null || {
    echo "Missing required v127 recovery runtime: $required" >&2
    exit 1
  }
done
workspace="$(mktemp -d)"
cleanup() {
  git -C "$repo_root" worktree remove --force "$workspace/foodex-125" >/dev/null 2>&1 || true
  rm -rf "$workspace"
}
trap cleanup EXIT

git -C "$repo_root" worktree add --detach "$workspace/foodex-125" v1.0.125
backend="$workspace/foodex-125/backend"
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
DB_DATABASE=foodex_upgrade_127
DB_USERNAME=root
DB_PASSWORD=foodex_ci_only
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
ENV
php artisan key:generate --force
php artisan migrate --force --no-interaction
php artisan db:seed --class='Database\Seeders\CoreReferenceSeeder' --force --no-interaction

php <<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$now = date('Y-m-d H:i:s');
$user = DB::table('users')->insertGetId([
    'name'=>'Upgrade 125 Owner', 'email'=>'upgrade-125@example.invalid',
    'password'=>password_hash('CI-only no login', PASSWORD_BCRYPT),
    'is_active'=>true,
    'created_at'=>$now, 'updated_at'=>$now,
]);

if (! Schema::hasTable('platform_owner_registry')) {
    throw new RuntimeException('v125 platform owner registry baseline is missing');
}
if (! DB::table('platform_owner_registry')->exists()) {
    DB::table('platform_owner_registry')->insert([
        'id'=>1, 'user_id'=>$user, 'provisioned_at'=>$now,
        'created_at'=>$now, 'updated_at'=>$now,
    ]);
}
// Simulate interruption after the table was created but before Laravel
// recorded this DDL migration; the next run must not destroy the owner.
DB::table('migrations')
    ->where('migration', '2026_11_08_071000_create_platform_owner_registry')
    ->delete();

$permissionId = DB::table('permissions')
    ->where('code','orders.customer_service_discount')->value('id');
if (!$permissionId) {
    throw new RuntimeException('v125 CoreReferenceSeeder did not create the permission');
}
DB::table('permission_role')->where('permission_id', $permissionId)->delete();
DB::table('permissions')->where('id', $permissionId)->delete();

$type = DB::table('store_types')->where('code','B2B')->value('id');
if (!$type) throw new RuntimeException('Missing B2B store type');
$store = DB::table('stores')->insertGetId([
    'store_type_id'=>$type, 'code'=>'upgrade-127-ci', 'name'=>'Upgrade 127 CI',
    'created_at'=>$now, 'updated_at'=>$now,
]);
$customer = DB::table('customers')->insertGetId([
    'name'=>'Upgrade 125 Customer', 'type'=>'b2b',
    'created_at'=>$now, 'updated_at'=>$now,
]);
$order = DB::table('orders')->insertGetId([
    'store_id'=>$store, 'customer_id'=>$customer,
    'order_number'=>'UPGRADE-125-127-CI', 'channel'=>'b2b',
    'status'=>'pending', 'created_at'=>$now, 'updated_at'=>$now,
]);
DB::table('order_customer_service_assignments')->insert([
    'order_id'=>$order, 'assigned_user_id'=>$user,
    'assigned_by_user_id'=>$user, 'reason'=>'keep assignment',
    'assigned_at'=>$now, 'unassigned_at'=>null,
    'created_at'=>$now, 'updated_at'=>$now,
]);
DB::table('order_customer_service_discounts')->insert([
    'order_id'=>$order, 'applied_by_user_id'=>$user,
    'idempotency_key'=>'4bf3e1bc-0d57-473b-a99d-b4c21125b127',
    'revision'=>1, 'percentage'=>'5.000',
    'reason'=>'keep customer discount audit',
    'prior_customer_service_discount'=>'0.000',
    'customer_service_discount'=>'5.000',
    'discount_total_before'=>'0.000',
    'discount_total_after'=>'5.000',
    'grand_total_before'=>'100.000',
    'grand_total_after'=>'95.000',
    'order_status'=>'pending',
    'created_at'=>$now, 'updated_at'=>$now,
]);
file_put_contents(storage_path('app/upgrade-125-127-upload-marker.txt'), 'KEEP_UPLOAD_BYTES');
PHP

before_env="$(sha256sum .env | cut -d' ' -f1)"
before_upload="$(sha256sum storage/app/upgrade-125-127-upload-marker.txt | cut -d' ' -f1)"
unzip -q -o "$package" -d "$workspace/foodex-125"
test "$(tr -d '[:space:]' < "$workspace/foodex-125/VERSION")" = "1.0.127"
test "$before_env" = "$(sha256sum .env | cut -d' ' -f1)"
test "$before_upload" = "$(sha256sum storage/app/upgrade-125-127-upload-marker.txt | cut -d' ' -f1)"

php artisan optimize:clear
php artisan migrate --force --no-interaction
# Reproduce the same role-reconciliation phase the production updater runs.
php artisan db:seed --class='Database\Seeders\BuiltInJobRoleSeeder' --force --no-interaction
php artisan migrate --force --no-interaction

php <<'PHP'
<?php
use Illuminate\Support\Facades\DB;
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = DB::table('users')->where('email','upgrade-125@example.invalid')->first();
if (!$user || !DB::table('platform_owner_registry')->where('id',1)->where('user_id',$user->id)->exists()) {
    throw new RuntimeException('Existing platform owner identity not preserved');
}
if (! DB::table('orders')->where('order_number','UPGRADE-125-127-CI')->exists()) {
    throw new RuntimeException('Upgrade deleted an existing order');
}
$orderId = DB::table('orders')->where('order_number','UPGRADE-125-127-CI')->value('id');
if (! DB::table('order_customer_service_assignments')
    ->where('order_id',$orderId)->where('assigned_user_id',$user->id)
    ->whereNull('unassigned_at')->exists()) {
    throw new RuntimeException('Customer Service assignment history not preserved');
}
if (! DB::table('order_customer_service_discounts')
    ->where('order_id',$orderId)
    ->where('idempotency_key','4bf3e1bc-0d57-473b-a99d-b4c21125b127')
    ->where('reason','keep customer discount audit')->exists()) {
    throw new RuntimeException('Customer discount audit history not preserved');
}
if (! DB::table('permissions')->where('code','orders.customer_service_discount')->exists()) {
    throw new RuntimeException('127 failed to restore required Customer Service permission');
}
foreach ([
    '2026_11_08_071000_create_platform_owner_registry',
    '2026_11_08_072000_restore_customer_service_discount_permission'
] as $migration) {
    if (! DB::table('migrations')->where('migration',$migration)->exists()) {
        throw new RuntimeException('Missing completed upgrade migration '.$migration);
    }
}
PHP

test "$before_env" = "$(sha256sum .env | cut -d' ' -f1)"
test "$before_upload" = "$(sha256sum storage/app/upgrade-125-127-upload-marker.txt | cut -d' ' -f1)"
echo "PASS: actual v125 -> v127 ZIP, interrupted owner migration, RBAC, owner, orders, assignments, discount audit, .env and uploads preserved"
