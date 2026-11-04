<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\MinimumOrderPolicy;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WholesaleMinimumOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_admin_changes_minimum_immediately_and_zero_disables_b2b_checkout_gate(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN');
        $policy = app(MinimumOrderPolicy::class);

        $this->assertSame(1500.0, $policy->minimumForStore($storeId));

        $this->actingAs($admin)
            ->get(route('admin.b2b.module', ['module' => 'storefront']))
            ->assertOk()
            ->assertSee('data-wholesale-minimum-order')
            ->assertSee('name="minimum_order_egp"', false);

        $this->actingAs($admin)->put(route('admin.b2b.storefront.minimum-order'), [
            'minimum_order_egp' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'store_id' => $storeId,
            'key' => MinimumOrderPolicy::WHOLESALE_SETTING_KEY,
            'value' => '0',
        ]);
        $this->assertSame(0.0, $policy->minimumForStore($storeId));
        $policy->assertEligible($this->quote($storeId, 100));

        $this->actingAs($admin)->put(route('admin.b2b.storefront.minimum-order'), [
            'minimum_order_egp' => '2000.25',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2000.25, $policy->minimumForStore($storeId));
        $policy->assertEligible($this->quote($storeId, 2000.25));

        try {
            $policy->assertEligible($this->quote($storeId, 1999.25));
            $this->fail('Below-minimum B2B checkout should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
            $this->assertNotEmpty($exception->errors()['cart']);
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'b2b.checkout.minimum_order.updated',
            'user_id' => $admin->id,
        ]);
    }

    public function test_invalid_minimum_and_retail_admin_cannot_change_wholesale_minimum(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN');

        $this->actingAs($admin)->put(route('admin.b2b.storefront.minimum-order'), [
            'minimum_order_egp' => '-1',
        ])->assertSessionHasErrors('minimum_order_egp');

        $this->actingAs($admin)->put(route('admin.b2b.storefront.minimum-order'), [
            'minimum_order_egp' => '1.234',
        ])->assertSessionHasErrors('minimum_order_egp');

        $retail = $this->roleUser('B2C_STORE_ADMIN');
        $this->actingAs($retail)->put(route('admin.b2b.storefront.minimum-order'), [
            'minimum_order_egp' => '0',
        ])->assertNotFound();

        $this->assertSame(1500.0, app(MinimumOrderPolicy::class)->minimumForStore($storeId));
    }

    public function test_customer_cart_and_checkout_options_expose_same_minimum(): void
    {
        $cart = file_get_contents(app_path('Http/Controllers/Api/V1/GuestCartController.php'));
        $options = file_get_contents(app_path('Http/Controllers/Api/V1/StorefrontController.php'));
        $checkout = file_get_contents(app_path('Http/Controllers/Api/V1/CheckoutController.php'));
        $flutter = file_get_contents(base_path('../apps/customer_app/lib/features/storefront/wholesale_multistore_screens.dart'));

        $this->assertIsString($cart);
        $this->assertIsString($options);
        $this->assertIsString($checkout);
        $this->assertIsString($flutter);
        $this->assertStringContainsString("'minimum_order_egp' =>", $cart);
        $this->assertStringContainsString("'minimum_order_egp' =>", $options);
        $this->assertStringContainsString('MinimumOrderPolicy::class)->assertEligible($quote)', $checkout);
        $this->assertStringContainsString('b2b-minimum-order-popup', $flutter);
        $this->assertStringContainsString("_wholesaleMinimumRemaining(", $flutter);
        $this->assertStringNotContainsString('(1500 - merchandiseEstimate)', $flutter);
    }

    /** @return array<string, mixed> */
    private function quote(int $storeId, float $subtotal): array
    {
        return [
            'store_id' => $storeId,
            'channel' => 'b2b',
            'currency' => 'EGP',
            'subtotal' => $subtotal,
            'discount_total' => 0,
        ];
    }

    private function roleUser(string $role): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => strtolower($role).'-minimum-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
