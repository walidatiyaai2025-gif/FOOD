<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MobileAppSetting;
use App\Models\Role;
use App\Models\StoreReviewerAccount;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreSubmissionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_public_store_review_routes_are_reachable_without_authentication(): void
    {
        foreach (['/privacy', '/terms', '/support', '/account-deletion'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_runtime_footer_configuration_controls_visibility_only(): void
    {
        MobileAppSetting::query()->create([
            'app' => 'customer',
            'environment' => 'production',
            'display_name' => 'FOODEX Customer',
            'published_version' => '99.99.99',
            'published_build' => '999999',
            'footer_display_mode' => 'hidden',
            'delete_account_url' => 'https://foodex.50sols.com/account-deletion',
        ]);

        $this->getJson('/api/v1/mobile/runtime?app=customer&environment=production&locale=en')
            ->assertOk()
            ->assertJsonPath('data.footer_display_mode', 'hidden')
            ->assertJsonPath('data.delete_account_url', 'https://foodex.50sols.com/account-deletion')
            ->assertJsonPath('data.published_version', '99.99.99');
    }

    public function test_reviewer_secret_is_encrypted_masked_and_never_rendered(): void
    {
        $admin = $this->admin('store-admin@example.test');
        $reviewer = $this->customer('reviewer@example.test', 'reviewer-pass-123');

        $this->actingAs($admin)->put('/admin/settings/mobile/reviewer', [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'persona' => 'customer_reviewer',
            'identifier_type' => 'email',
            'identifier' => $reviewer->email,
            'reviewer_secret' => 'reviewer-pass-123',
            'context_json' => '{"store_id":1,"channel":"b2c"}',
            'reviewer_instructions' => 'Sign in and open the account page.',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $configured = StoreReviewerAccount::query()->firstOrFail();
        $raw = (string) $configured->getRawOriginal('secret_encrypted');
        $this->assertNotSame('reviewer-pass-123', $raw);
        $this->assertStringNotContainsString('reviewer-pass-123', $raw);
        $this->assertSame('••••••••', $configured->maskedSecret());

        $this->actingAs($admin)
            ->get('/admin/settings/mobile?app=customer&environment=production')
            ->assertOk()
            ->assertDontSee('reviewer-pass-123');

        $this->actingAs($admin)
            ->post('/admin/settings/mobile/reviewer/'.$configured->id.'/test')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('store_reviewer_accounts', [
            'id' => $configured->id,
            'readiness_status' => 'PASS',
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'store_reviewer_account.updated',
            'after' => 'reviewer-pass-123',
        ]);
    }

    public function test_customer_can_request_verified_deletion_and_required_records_are_not_blindly_deleted(): void
    {
        $user = $this->customer('delete-me@example.test', 'delete-me-123');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/account-deletion', [
            'password' => 'delete-me-123',
            'confirmation' => true,
        ])->assertSuccessful();

        $this->assertSame('COMPLETED', $response->json('data.status'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('account_deletion_requests', [
            'user_id' => $user->id,
            'status' => 'COMPLETED',
        ]);

        $fresh = User::query()->findOrFail($user->id);
        $this->assertStringStartsWith('deleted+', $fresh->email);
        $this->assertFalse(Hash::check('delete-me-123', (string) $fresh->password));

        $customer = Customer::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($customer->phone);
        $this->assertStringStartsWith('deleted+', (string) $customer->email);
    }

    public function test_wrong_password_cannot_request_account_deletion(): void
    {
        $user = $this->customer('keep-me@example.test', 'keep-me-123');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account-deletion', [
            'password' => 'wrong-password',
            'confirmation' => true,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('account_deletion_requests', [
            'user_id' => $user->id,
        ]);
        $this->assertTrue(User::query()->findOrFail($user->id)->is_active);
    }

    private function customer(string $email, string $password): User
    {
        $user = User::query()->create([
            'name' => 'Store Reviewer',
            'email' => $email,
            'password' => $password,
            'locale' => 'en',
            'is_active' => true,
        ]);

        Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => $user->name,
            'phone' => '+96550000000',
            'email' => $email,
        ]);

        return $user;
    }

    private function admin(string $email): User
    {
        $admin = User::query()->create([
            'name' => 'Super',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail(),
        );

        return $admin;
    }
}
