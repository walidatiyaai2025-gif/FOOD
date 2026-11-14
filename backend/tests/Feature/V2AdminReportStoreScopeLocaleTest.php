<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2AdminReportStoreScopeLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_admin_gets_arabic_and_english_missing_store_validation(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$ownStore, $foreignStore] = $this->twoStores();
        $user = $this->storeAdmin($ownStore, 'ar');
        Sanctum::actingAs($user);

        // An explicitly empty language preference must not pick an implicit
        // framework/runner English fallback over the authenticated user's Arabic.
        $this->withHeader('Accept-Language', '')
            ->getJson('/api/v1/admin/reports/dashboard')
            ->assertUnprocessable()
            ->assertJsonPath('errors.store_id.0', __('reports.store_required', [], 'ar'));

        $this->withHeader('Accept-Language', 'en-US,en;q=0.8')
            ->getJson('/api/v1/admin/reports/dashboard?store_id=0')
            ->assertUnprocessable()
            ->assertJsonPath('errors.store_id.0', __('reports.store_required', [], 'en'));

        $user->update(['locale' => 'en']);
        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.8')
            ->getJson('/api/v1/admin/reports/dashboard')
            ->assertUnprocessable()
            ->assertJsonPath('errors.store_id.0', __('reports.store_required', [], 'en'));

        $this->withHeader('Accept-Language', 'ar-KW,ar;q=0.9,en;q=0.7')
            ->getJson('/api/v1/admin/reports/dashboard')
            ->assertUnprocessable()
            ->assertJsonPath('errors.store_id.0', __('reports.store_required', [], 'ar'));

        $this->getJson('/api/v1/admin/reports/dashboard?store_id='.$foreignStore)->assertForbidden();
        $this->getJson('/api/v1/admin/reports/dashboard?store_id='.$ownStore)
            ->assertOk()
            ->assertJsonPath('data.scope', 'store')
            ->assertJsonPath('data.store_id', $ownStore);
    }

    public function test_super_admin_platform_reporting_requires_no_store_selection(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $admin = User::query()->create([
            'name' => 'V2 Report Super', 'email' => 'v2-report-super@example.test',
            'password' => 'password', 'locale' => 'ar', 'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope', 'platform')
            ->assertJsonPath('data.store_id', null);
    }

    private function twoStores(): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $ids = [];
        foreach (['A', 'B'] as $suffix) {
            $ids[] = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $typeId,
                'code' => 'V2-RPT-LOCALE-'.$suffix,
                'name' => 'V2 Report '.$suffix,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function storeAdmin(int $storeId, string $locale): User
    {
        $user = User::query()->create([
            'name' => 'V2 Scoped Reporter',
            'email' => 'v2-scoped-report@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $user->roles()->attach($role);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
