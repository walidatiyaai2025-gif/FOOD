<?php

use App\Enterprise\Organization\OrganizationHierarchyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('enterprise_companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'enterprise_companies_org_code_unique');
        });

        Schema::create('enterprise_legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'enterprise_legal_entities_company_code_unique');
        });

        Schema::create('enterprise_business_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'enterprise_business_units_entity_code_unique');
        });

        Schema::create('enterprise_store_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('enterprise_business_units')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_unit_id', 'store_id'], 'enterprise_store_scopes_unit_store_idx');
        });

        $now = now();
        $organizationId = DB::table('enterprise_organizations')->insertGetId([
            'code' => OrganizationHierarchyService::DEFAULT_ORGANIZATION,
            'name' => 'FOODEX Platform',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $companyId = DB::table('enterprise_companies')->insertGetId([
            'organization_id' => $organizationId,
            'code' => OrganizationHierarchyService::DEFAULT_COMPANY,
            'name' => 'Default Company',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $legalEntityId = DB::table('enterprise_legal_entities')->insertGetId([
            'company_id' => $companyId,
            'code' => OrganizationHierarchyService::DEFAULT_LEGAL_ENTITY,
            'name' => 'Default Legal Entity',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $businessUnitId = DB::table('enterprise_business_units')->insertGetId([
            'legal_entity_id' => $legalEntityId,
            'code' => OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT,
            'name' => 'Legacy Operations',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('stores')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($stores) use ($businessUnitId, $now): void {
                $rows = collect($stores)->map(static fn ($store): array => [
                    'store_id' => (int) $store->id,
                    'business_unit_id' => $businessUnitId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('enterprise_store_scopes')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_store_scopes');
        Schema::dropIfExists('enterprise_business_units');
        Schema::dropIfExists('enterprise_legal_entities');
        Schema::dropIfExists('enterprise_companies');
        Schema::dropIfExists('enterprise_organizations');
    }
};
