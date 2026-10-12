<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\FoodexResetService;
use App\Services\LaunchCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Database\Seeders\DashboardDemoSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoodexResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_only_super_admin_can_open_reset_surface(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->get('/admin/settings/foodex-reset')
            ->assertOk()
            ->assertSee('data-foodex-reset="true"', false)
            ->assertSee('RESET FOODEX DATA');

        $regular = User::query()->create([
            'name' => 'Regular Admin',
            'email' => 'regular-reset@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($regular)
            ->get('/admin/settings/foodex-reset')
            ->assertForbidden();
    }

    public function test_reset_is_hard_blocked_until_authoritative_seed_contract_exists(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->get('/admin/settings/foodex-reset')->assertOk();
        $this->actingAs($owner)
            ->post('/admin/settings/foodex-reset', [
                'confirmation' => FoodexResetService::CONFIRMATION,
                'understood' => '1',
                'current_password' => 'password',
            ])
            ->assertSessionHasErrors('reset');
    }

    public function test_complete_reset_preserves_identity_and_rbac_and_can_run_again(): void
    {
        $this->seed(DashboardDemoSeeder::class);
        $owner = $this->superAdmin();
        $users = DB::table('users')->count();
        $stores = DB::table('stores')->count();
        $this->bindCatalog();
        $this->assertTrue(app(FoodexResetService::class)->preview()['references_ready']);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($owner)->get('/admin/settings/foodex-reset')->assertOk();
            $this->actingAs($owner)->post('/admin/settings/foodex-reset', $this->confirmation())
                ->assertSessionHasNoErrors()->assertRedirect(route('admin.foodex-reset.index'));
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('users', $users);
            $this->assertDatabaseCount('stores', $stores);
            $this->assertTrue($owner->fresh()->hasRole('SUPER_ADMIN'));
        }
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'system.launch_reset')->count());
    }

    public function test_seed_failure_rolls_back_deletions_and_no_completion_is_audited(): void
    {
        $this->seed(DashboardDemoSeeder::class);
        $before = DB::table('orders')->count();
        $this->bindCatalog(true);
        $owner = $this->superAdmin();
        $this->actingAs($owner)->get('/admin/settings/foodex-reset')->assertOk();
        $this->actingAs($owner)->post('/admin/settings/foodex-reset', $this->confirmation())->assertSessionHasErrors('reset');
        $this->assertDatabaseCount('orders', $before);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'system.launch_reset')->count());
    }

    public function test_same_count_data_change_invalidates_preview_and_double_submit_is_rejected(): void
    {
        $this->bindCatalog();
        $owner = $this->superAdmin();
        $this->actingAs($owner)->get('/admin/settings/foodex-reset')->assertOk();
        DB::table('units')->where('id', DB::table('units')->value('id'))->update(['name' => 'Changed after preview']);
        $this->actingAs($owner)->post('/admin/settings/foodex-reset', $this->confirmation())->assertSessionHasErrors('reset');
        $this->actingAs($owner)->post('/admin/settings/foodex-reset', $this->confirmation())->assertSessionHasErrors('reset');
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'system.launch_reset')->count());
    }

    public function test_expired_preview_and_wrong_password_cannot_reset(): void
    {
        $this->bindCatalog();
        $owner = $this->superAdmin();
        $this->actingAs($owner)->get('/admin/settings/foodex-reset')->assertOk();
        $this->actingAs($owner)->post('/admin/settings/foodex-reset', array_replace($this->confirmation(), ['current_password' => 'wrong']))
            ->assertSessionHasErrors('current_password');
        $this->travel(6)->minutes();
        $this->actingAs($owner)->post('/admin/settings/foodex-reset', $this->confirmation())->assertSessionHasErrors('reset');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unknown_dependency_is_blocked_without_disabling_foreign_keys(): void
    {
        $this->bindCatalog();
        Schema::create('reset_unknown_dependents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
        });
        DB::table('reset_unknown_dependents')->insert(['unit_id' => DB::table('units')->value('id')]);
        $this->assertFalse(app(FoodexResetService::class)->preview()['references_ready']);
        Schema::drop('reset_unknown_dependents');
    }

    public function test_production_cannot_be_enabled_by_confirmation_or_backup_text(): void
    {
        $this->bindCatalog();
        $this->app->instance('env', 'production');
        config(['foodex_reset.production_enabled' => false]);
        $preview = app(FoodexResetService::class)->preview();
        $this->assertFalse($preview['production_ready']);
        $this->assertFalse($preview['ready']);
        $this->app->instance('env', 'testing');
    }

    private function confirmation(): array
    {
        return ['confirmation' => FoodexResetService::CONFIRMATION, 'understood' => '1', 'current_password' => 'password'];
    }

    /** Test-only adapter exercises the transaction; it is not official catalog evidence. */
    private function bindCatalog(bool $fail = false): void
    {
        $catalog = \Mockery::mock(LaunchCatalog::class);
        $catalog->shouldReceive('preflight')->andReturn(['ready' => true, 'fingerprint' => 'test-contract']);
        if ($fail) {
            $catalog->shouldReceive('reseed')->andThrow(new \RuntimeException('Test seed failure'));
        } else {
            $catalog->shouldReceive('reseed')->andReturnNull();
        }
        $this->app->instance(LaunchCatalog::class, $catalog);
    }

    public function test_arabic_reset_surface_is_rtl_and_localized(): void
    {
        $owner = $this->superAdmin('ar');

        $this->actingAs($owner)
            ->get('/admin/settings/foodex-reset')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('إعادة ضبط وتهيئة FOODEX');
    }

    private function superAdmin(string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => 'FOODEX Reset Owner',
            'email' => 'reset-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
