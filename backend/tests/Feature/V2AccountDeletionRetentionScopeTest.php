<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2AccountDeletionRetentionScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2b_domain_account_requires_review_when_legacy_account_link_points_to_another_customer(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherLegacy = $this->legacyCustomer($other, 'b2b');
        $domainCustomer = B2bCustomer::query()->create([
            'user_id' => $user->id,
            'name' => 'Wholesale domain owner',
            'email' => 'wholesale-domain@example.test',
        ]);

        $accountId = DB::table('b2b_accounts')->insertGetId([
            'customer_id' => $otherLegacy->id,
            'b2b_customer_id' => $domainCustomer->id,
            'company_name' => 'Wholesale account',
            'status' => 'active',
            'credit_limit' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletion = app(AccountDeletionService::class)->request($user, 'password');

        $this->assertSame('PENDING_RETENTION', $deletion->status);
        $this->assertTrue($deletion->retention_context['customer_account']);
        $this->assertTrue($deletion->retention_context['financial_review_required']);
        $this->assertNull($deletion->anonymized_at);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseHas('b2b_accounts', ['id' => $accountId, 'b2b_customer_id' => $domainCustomer->id]);
        $this->assertDatabaseHas('b2b_customers', ['id' => $domainCustomer->id, 'name' => 'Wholesale domain owner']);
    }

    public function test_unrelated_b2b_id_collision_does_not_block_a_retail_customer_deletion(): void
    {
        $retailUser = User::factory()->create();
        $retail = $this->legacyCustomer($retailUser, 'b2c');
        $otherUser = User::factory()->create();
        $otherB2b = B2bCustomer::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other wholesaler',
            'email' => 'other-wholesaler@example.test',
        ]);
        $otherLegacy = $this->legacyCustomer($otherUser, 'b2b');

        // Each table starts its own ID sequence. The B2B domain ID must
        // never be interpreted as the retail user's legacy customer ID.
        $this->assertSame($retail->id, $otherB2b->id);

        $accountId = DB::table('b2b_accounts')->insertGetId([
            'customer_id' => $otherLegacy->id,
            'b2b_customer_id' => $otherB2b->id,
            'company_name' => 'Unrelated account',
            'status' => 'active',
            'credit_limit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletion = app(AccountDeletionService::class)->request($retailUser, 'password');

        $this->assertSame('COMPLETED', $deletion->status);
        $this->assertFalse($deletion->retention_context['financial_review_required']);
        $this->assertNotNull($deletion->anonymized_at);
        $this->assertDatabaseHas('b2b_accounts', ['id' => $accountId, 'customer_id' => $otherLegacy->id]);
        $this->assertDatabaseHas('b2b_customers', ['id' => $otherB2b->id, 'name' => 'Other wholesaler']);
    }

    public function test_b2b_contractual_profile_without_a_denormalized_balance_requires_review_via_http(): void
    {
        $user = User::factory()->create();
        $this->legacyCustomer($user, 'b2b');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account-deletion', [
            'password' => 'password',
            'confirmation' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_RETENTION')
            ->assertJsonPath('data.retention.financial_review_required', true);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseHas('account_deletion_requests', [
            'user_id' => $user->id,
            'status' => 'PENDING_RETENTION',
        ]);
    }

    public function test_operational_van_identity_remains_managed_even_without_customer_orders(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'code' => 'VAN_OPERATOR',
            'name' => 'Van Operator',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => true,
        ]);
        $user->roles()->attach($role->id);

        $deletion = app(AccountDeletionService::class)->request($user, 'password');

        $this->assertSame('OPERATIONAL_REVIEW_REQUIRED', $deletion->status);
        $this->assertNull($deletion->anonymized_at);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    public function test_wrong_password_does_not_create_deletion_request(): void
    {
        $user = User::factory()->create();
        $this->legacyCustomer($user, 'b2c');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            app(AccountDeletionService::class)->request($user, 'wrong-password');
        } finally {
            $this->assertDatabaseMissing('account_deletion_requests', ['user_id' => $user->id]);
        }
    }

    private function legacyCustomer(User $user, string $type): Customer
    {
        return Customer::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'name' => 'Customer '.$user->id,
            'email' => 'customer-'.$user->id.'@example.test',
        ]);
    }
}
