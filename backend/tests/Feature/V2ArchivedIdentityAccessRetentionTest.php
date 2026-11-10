<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2ArchivedIdentityAccessRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_admin_cannot_use_dashboard_even_with_an_existing_session(): void
    {
        $actor = $this->userWithRole('SUPER_ADMIN');
        $target = $this->userWithRole('SUPER_ADMIN');

        Sanctum::actingAs($actor);
        $this->patchJson("/api/v1/admin/security/users/{$target->id}/status", [
            'is_active' => false,
            'reason' => 'V2 archive',
        ])->assertOk();

        $this->actingAs($target)->get('/admin/security')->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => 0]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_archived_customer_has_no_mobile_api_access_but_invoices_remain(): void
    {
        $actor = $this->userWithRole('SUPER_ADMIN');
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2b',
            'name' => 'Retained Customer',
            'email' => $user->email,
        ]);
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-ARCHIVE-HISTORY',
            'name' => 'Retention Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice = Invoice::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'invoice_number' => 'V2-ARCHIVE-INV-1',
            'total' => 75.000,
            'status' => 'issued',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/profile')->assertOk();
        app(RbacManager::class)->setUserActive(
            $actor,
            $user,
            false,
            'V2 account archive',
            Request::create('/admin/security', 'PATCH'),
        );

        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->getJson('/api/v1/invoices')->assertUnauthorized();
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'V2-ARCHIVE-INV-1',
        ]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.status_changed', 'auditable_id' => $user->id]);
    }

    public function test_archived_driver_and_van_are_denied_all_protected_runtime_routes(): void
    {
        $actor = $this->userWithRole('SUPER_ADMIN');

        foreach ([
            ['B2C_DRIVER', '/api/v1/driver/wallet'],
            ['VAN_OPERATOR', '/api/v1/van/visits'],
        ] as [$role, $endpoint]) {
            $user = $this->userWithRole($role);
            app(RbacManager::class)->setUserActive(
                $actor,
                $user,
                false,
                'V2 archive',
                Request::create('/admin/security', 'PATCH'),
            );
            Sanctum::actingAs($user);
            $this->getJson($endpoint)->assertUnauthorized();
            $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => 0]);
        }
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $user;
    }
}
