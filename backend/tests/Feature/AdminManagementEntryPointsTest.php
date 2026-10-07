<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_uses_administration_hub_and_management_centers_keep_cross_links(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Admin Control Center',
            'email' => 'admin-control-center@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee(route('admin.administration.index'))
            ->assertDontSee(route('admin.translations.index'))
            ->assertDontSee(route('admin.notifications.index'));

        $this->actingAs($user)
            ->get('/admin/settings/translations')
            ->assertOk()
            ->assertSee(route('admin.notifications.index'))
            ->assertSee(__('notifications.title'));

        $this->actingAs($user)
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee(route('admin.translations.index'))
            ->assertSee(__('admin.translations.title'));
    }
}
