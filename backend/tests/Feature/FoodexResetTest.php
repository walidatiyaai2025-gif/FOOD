<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\FoodexResetService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->post('/admin/settings/foodex-reset', [
                'confirmation' => FoodexResetService::CONFIRMATION,
            ])
            ->assertSessionHasErrors('confirmation');
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
