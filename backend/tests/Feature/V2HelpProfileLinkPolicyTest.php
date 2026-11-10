<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class V2HelpProfileLinkPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_help_is_a_public_route_but_profile_stays_under_management_access_middleware(): void
    {
        $help = Route::getRoutes()->getByName('public.support');
        $profile = Route::getRoutes()->getByName('admin.profile.index');

        $this->assertNotNull($help);
        $this->assertNotNull($profile);
        $this->assertContains('GET', $help->methods());
        $this->assertContains('GET', $profile->methods());
        $this->assertNotContains('management.dashboard', $help->gatherMiddleware());
        $this->assertContains('management.dashboard', $profile->gatherMiddleware());
    }

    public function test_guests_may_read_localized_help_but_cannot_open_profile_directly(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $this->get(route('public.support', ['lang' => $locale]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->get(route('admin.profile.index'))
            ->assertRedirect(route('admin.b2c.login'));
    }

    public function test_active_wholesale_actor_may_open_only_their_own_localized_profile(): void
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user->forceFill(['locale' => $locale])->save();

            $this->actingAs($user->fresh())
                ->get(route('admin.profile.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->actingAs($user->fresh())
            ->get(route('public.support', ['lang' => 'en']))
            ->assertOk();
    }

    public function test_retail_store_actor_can_read_profile_without_wholesale_or_foreign_store_access(): void
    {
        $ownStore = $this->retailStore('P012-OWN');
        $foreignStore = $this->retailStore('P012-FOREIGN');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $ownStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.profile.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('admin.b2b.dashboard'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreignStore]))
            ->assertNotFound();
    }

    public function test_inactive_and_unassigned_accounts_cannot_follow_profile_direct_url(): void
    {
        $unassigned = User::factory()->create(['is_active' => true]);
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        foreach ([$unassigned, $inactive] as $actor) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.profile.index'))
                ->assertForbidden();
        }
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
