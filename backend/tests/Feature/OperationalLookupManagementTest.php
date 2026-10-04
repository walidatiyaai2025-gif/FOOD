<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationalLookupManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_operational_lookup_schema_and_canonical_codes_are_seeded(): void
    {
        $this->assertTrue(Schema::hasColumns('operational_lookups', [
            'type', 'code', 'label_ar', 'label_en', 'sort_order', 'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('b2b_price_tiers', ['name_ar', 'name_en', 'is_active']));

        foreach (['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'failed', 'delivered', 'cancelled'] as $code) {
            $this->assertDatabaseHas('operational_lookups', ['type' => 'order_status', 'code' => $code]);
        }

        foreach (['customer_no_answer', 'wrong_address', 'customer_refused', 'customer_absent', 'payment_issue', 'order_issue', 'other'] as $code) {
            $this->assertDatabaseHas('operational_lookups', ['type' => 'failed_delivery_reason', 'code' => $code]);
        }
    }

    public function test_system_lookup_center_has_five_bilingual_tabs_and_rtl_ltr(): void
    {
        $ar = $this->userWithRole('SUPER_ADMIN', 'ops-lookups-ar@example.test', 'ar');
        $this->actingAs($ar)->get('/admin/operations/lookups')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('أنواع عمليات الدفع')
            ->assertSee('طرق الدفع')
            ->assertSee('شرائح التسعير')
            ->assertSee('حالات الطلب')
            ->assertSee('أسباب تعذر التوصيل');

        $en = $this->userWithRole('SUPER_ADMIN', 'ops-lookups-en@example.test');
        $this->actingAs($en)->get('/admin/operations/lookups')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Payment operation types')
            ->assertSee('Payment methods')
            ->assertSee('Pricing tiers')
            ->assertSee('Order statuses')
            ->assertSee('Failed-delivery reasons');
    }

    public function test_code_is_immutable_and_updates_are_audited(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'ops-lookups-owner@example.test');

        $this->actingAs($admin)->post('/admin/operations/lookups/payment-methods', [
            'code' => 'wallet',
            'label_ar' => 'محفظة',
            'label_en' => 'Wallet',
            'sort_order' => 40,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $id = (int) DB::table('operational_lookups')
            ->where('type', 'payment_method')
            ->where('code', 'wallet')
            ->value('id');

        $this->actingAs($admin)->patch('/admin/operations/lookups/payment-methods/'.$id, [
            'code' => 'changed_code',
            'label_ar' => 'محفظة رقمية',
            'label_en' => 'Digital wallet',
            'sort_order' => 45,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('operational_lookups', [
            'id' => $id,
            'code' => 'wallet',
            'label_en' => 'Digital wallet',
            'sort_order' => 45,
        ]);
        $this->assertDatabaseMissing('operational_lookups', ['id' => $id, 'code' => 'changed_code']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'system_lookup.payment_method.updated',
            'auditable_id' => $id,
        ]);
    }

    public function test_inactive_values_are_not_returned_for_new_selection_but_history_row_remains(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'ops-lookups-active@example.test');
        $id = (int) DB::table('operational_lookups')
            ->where('type', 'failed_delivery_reason')
            ->where('code', 'wrong_address')
            ->value('id');

        $this->actingAs($admin)->patch('/admin/operations/lookups/failed-delivery-reasons/'.$id, [
            'label_ar' => 'العنوان غير صحيح',
            'label_en' => 'Wrong address',
            'sort_order' => 20,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('operational_lookups', [
            'id' => $id,
            'code' => 'wrong_address',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/lookups/failed-delivery-reasons')
            ->assertOk()
            ->assertJsonMissing(['code' => 'wrong_address'])
            ->assertJsonFragment(['code' => 'customer_no_answer']);

        $this->assertNotEmpty($response->json('data'));
    }

    public function test_non_super_admin_cannot_mutate_central_operational_lookup(): void
    {
        $admin = $this->userWithRole('B2B_ADMIN', 'ops-lookups-b2b@example.test');

        $this->actingAs($admin)->post('/admin/operations/lookups/order-statuses', [
            'code' => 'custom_status',
            'label_ar' => 'حالة مخصصة',
            'label_en' => 'Custom status',
            'sort_order' => 999,
            'is_active' => 1,
        ])->assertForbidden();

        $this->assertDatabaseMissing('operational_lookups', [
            'type' => 'order_status',
            'code' => 'custom_status',
        ]);
    }

    public function test_pricing_tier_edit_preserves_stable_code_and_legacy_name(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'ops-lookups-tier@example.test');
        $tier = DB::table('b2b_price_tiers')->where('code', 'STANDARD')->first();
        $this->assertNotNull($tier);

        $this->actingAs($admin)->patch('/admin/operations/lookups/pricing-tiers/'.$tier->id, [
            'code' => 'RENAMED',
            'label_ar' => 'قياسي محدث',
            'label_en' => 'Updated Standard',
            'sort_order' => 7,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('b2b_price_tiers', [
            'id' => $tier->id,
            'code' => 'STANDARD',
            'name' => 'Updated Standard',
            'name_ar' => 'قياسي محدث',
            'name_en' => 'Updated Standard',
            'priority' => 7,
            'is_active' => true,
        ]);
    }

    private function userWithRole(string $roleCode, string $email, string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
