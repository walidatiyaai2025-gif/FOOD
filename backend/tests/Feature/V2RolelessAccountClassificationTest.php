<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2RolelessAccountClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_security_users_filter_classifies_seven_unassigned_identities_without_mutating_them(): void
    {
        $admin = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        // Historic seven is an audit input, not a license to reassign/delete accounts.
        $customerIds = [];
        $reviewIds = [];
        for ($i = 1; $i <= 7; $i++) {
            $user = User::factory()->create([
                'name' => "Unassigned V2 {$i}",
                'is_active' => $i !== 7,
                'is_platform_customer' => $i <= 4,
            ]);
            if ($i <= 4) {
                $customerIds[] = $user->id;
            } else {
                $reviewIds[] = $user->id;
            }
        }

        $response = $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'assignment' => 'roleless']));
        $response->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 7
                && ! $users->getCollection()->contains('id', $admin->id))
            ->assertSee('Platform customer — no admin role required')
            ->assertSee('No assigned role — review account type')
            ->assertSee('data-roleless-classification="customer"', false)
            ->assertSee('data-roleless-classification="review"', false)
            ->assertSee('Unassigned V2 7');

        foreach (array_merge($customerIds, $reviewIds) as $id) {
            $this->assertDatabaseHas('users', ['id' => $id]);
            $this->assertDatabaseMissing('role_user', ['user_id' => $id]);
            $this->assertDatabaseMissing('user_store_roles', ['user_id' => $id]);
        }
    }

    public function test_filter_is_localized_and_does_not_erase_existing_search_or_status(): void
    {
        $admin = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);
        User::factory()->create(['name' => 'Searchable V2 Account', 'is_active' => false, 'is_platform_customer' => false]);
        User::factory()->create(['name' => 'Other V2 Account', 'is_active' => true, 'is_platform_customer' => true]);

        $this->actingAs($admin)
            ->get(route('admin.security.index', [
                'section' => 'users', 'assignment' => 'roleless', 'status' => 'inactive', 'q' => 'Searchable V2',
            ]))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('بلا دور مسند — يلزم مراجعة نوع الحساب')
            ->assertSee('Searchable V2 Account')
            ->assertDontSee('Other V2 Account');

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['assignment' => 'invalid']))
            ->assertOk()
            ->assertViewHas('assignmentFilter', 'all');
    }

    public function test_roleless_identity_cannot_read_security_account_classification(): void
    {
        $unassigned = User::factory()->create([
            'is_active' => true,
            'is_platform_customer' => true,
            'locale' => 'en',
        ]);

        $this->actingAs($unassigned)
            ->get(route('admin.security.index', ['assignment' => 'roleless']))
            ->assertForbidden();
    }
}
