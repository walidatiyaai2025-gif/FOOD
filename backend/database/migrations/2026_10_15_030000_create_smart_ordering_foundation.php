<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_ordering_sites', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('runtime_state', 24)->default('DISABLED');
            $table->boolean('master_enabled')->default(false);
            $table->string('custom_domain')->nullable()->unique();
            $table->char('custom_domain_verification_token_hash', 64)->nullable();
            $table->timestamp('custom_domain_verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['runtime_state', 'master_enabled']);
        });

        Schema::create('smart_ordering_site_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->string('slug', 64)->unique();
            $table->boolean('redirect_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('smart_ordering_branches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name_ar')->nullable();
            $table->string('name_en');
            $table->string('timezone', 64)->default('Asia/Kuwait');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'code']);
            $table->index(['site_id', 'is_active']);
        });

        Schema::create('smart_ordering_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->string('scope_key', 64)->default('site');
            $table->string('module', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['site_id', 'scope_key', 'module'], 'smart_ordering_entitlement_scope_unique');
        });

        Schema::create('smart_ordering_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedInteger('schema_version')->default(1);
            $table->longText('payload');
            $table->char('checksum', 64);
            $table->foreignId('parent_revision_id')->nullable()
                ->constrained('smart_ordering_revisions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'created_at']);
        });

        $this->registerPermissions();
        $this->bootstrapExistingRetailStores();
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_revisions');
        Schema::dropIfExists('smart_ordering_entitlements');
        Schema::dropIfExists('smart_ordering_branches');
        Schema::dropIfExists('smart_ordering_site_aliases');
        Schema::dropIfExists('smart_ordering_sites');

        $codes = ['smart_ordering.view', 'smart_ordering.manage', 'smart_ordering.publish'];
        $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    private function registerPermissions(): void
    {
        $now = now();
        $definitions = [
            'smart_ordering.view' => 'View Smart Ordering configuration',
            'smart_ordering.manage' => 'Manage Smart Ordering runtime, site and menu configuration',
            'smart_ordering.publish' => 'Publish Smart Ordering revisions',
        ];

        DB::table('permissions')->upsert(
            collect($definitions)->map(fn (string $name, string $code): array => [
                'code' => $code,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['code'],
            ['name', 'updated_at'],
        );

        $roleId = DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
        if ($roleId === null) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('code', array_keys($definitions))->pluck('id') as $permissionId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => (int) $permissionId,
                'role_id' => (int) $roleId,
            ]);
        }
    }

    private function bootstrapExistingRetailStores(): void
    {
        $stores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.id')
            ->get(['stores.id', 'stores.code', 'stores.name']);

        foreach ($stores as $store) {
            $storeId = (int) $store->id;
            if (DB::table('smart_ordering_sites')->where('store_id', $storeId)->exists()) {
                continue;
            }

            $slug = $this->availableSlug((string) ($store->code ?: $store->name), $storeId);
            $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'slug' => $slug,
                'runtime_state' => 'DISABLED',
                'master_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->seedEntitlements($siteId);
            $this->createInitialRevision($siteId, $slug, (string) $store->name);
        }
    }

    private function availableSlug(string $source, int $storeId): string
    {
        $base = Str::slug($source);
        if ($base === '' || in_array($base, (array) config('smart_ordering.reserved_slugs', []), true)) {
            $base = 'restaurant-'.strtolower(Str::random(8));
        }

        $base = Str::limit($base, 50, '');
        $candidate = $base;
        $suffix = 1;

        while (DB::table('smart_ordering_sites')->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = Str::limit($base, 54, '').'-'.$suffix;
        }

        return $candidate;
    }

    private function seedEntitlements(int $siteId): void
    {
        $now = now();
        $rows = collect((array) config('smart_ordering.entitlements', []))
            ->filter('is_string')
            ->map(fn (string $module): array => [
                'site_id' => $siteId,
                'branch_id' => null,
                'scope_key' => 'site',
                'module' => $module,
                'enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all();

        if ($rows !== []) {
            DB::table('smart_ordering_entitlements')->insert($rows);
        }
    }

    private function createInitialRevision(int $siteId, string $slug, string $name): void
    {
        $payload = [
            'schema_version' => 1,
            'site' => ['slug' => $slug, 'name_ar' => null, 'name_en' => $name],
        ];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::table('smart_ordering_revisions')->insert([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'status' => 'published',
            'schema_version' => 1,
            'payload' => $encoded,
            'checksum' => hash('sha256', $encoded),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
