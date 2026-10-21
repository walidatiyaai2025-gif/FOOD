<?php

namespace Tests\Feature;

use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerAppContactCaseTest extends TestCase
{
    use RefreshDatabase;

    private int $retailStoreId;
    private int $otherStoreId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);

        $this->store('B2B', 'WHOLESALE-MAIN', 'Main Wholesale');
        $this->retailStoreId = $this->store('B2C', 'RETAIL-CONTACT', 'Retail Contact');
        $this->otherStoreId = $this->store('B2C', 'RETAIL-OTHER', 'Retail Other');
    }

    public function test_customer_app_contact_creates_canonical_open_complaint_case(): void
    {
        $user = $this->customer('contact-customer@example.test', '+96550000001');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/customer-service/contact', [
            'store_id' => $this->retailStoreId,
            'phone' => '+96551112222',
            'message' => 'أحتاج مساعدة من خدمة العملاء.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.type', 'complaint')
            ->assertJsonPath('data.source', 'customer_app');

        $caseId = (int) $response->json('data.id');
        $platformCustomerId = (int) DB::table('platform_customers')
            ->where('user_id', $user->getKey())
            ->value('id');

        $this->assertDatabaseHas('customer_service_cases', [
            'id' => $caseId,
            'store_id' => $this->retailStoreId,
            'platform_customer_id' => $platformCustomerId,
            'type' => 'complaint',
            'status' => 'open',
            'priority' => 'normal',
            'queue' => 'general',
            'source' => 'customer_app',
            'subject' => 'Customer App contact',
            'contact_phone' => '+96551112222',
            'description' => 'أحتاج مساعدة من خدمة العملاء.',
        ]);

        $this->assertNotNull(
            DB::table('customer_service_cases')
                ->where('id', $caseId)
                ->value('sla_target_at'),
        );
    }

    public function test_customer_cannot_spoof_unrelated_store_context(): void
    {
        $user = $this->customer('scoped-contact@example.test', '+96550000002');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/customer-service/contact', [
            'store_id' => $this->otherStoreId,
            'phone' => '+96552223333',
            'message' => 'This must not cross store scope.',
        ])->assertNotFound();

        $this->assertDatabaseMissing('customer_service_cases', [
            'contact_phone' => '+96552223333',
            'source' => 'customer_app',
        ]);
    }

    public function test_contact_requires_phone_and_message(): void
    {
        $user = $this->customer('validation-contact@example.test', '+96550000003');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/customer-service/contact', [
            'store_id' => $this->retailStoreId,
            'phone' => '',
            'message' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'message']);
    }

    private function customer(string $email, string $phone)
    {
        return app(PlatformCustomerService::class)->register([
            'name' => 'Contact Customer',
            'email' => $email,
            'phone' => $phone,
            'password' => Str::random(40),
            'locale' => 'en',
            'store_id' => $this->retailStoreId,
        ]);
    }

    private function store(string $type, string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
