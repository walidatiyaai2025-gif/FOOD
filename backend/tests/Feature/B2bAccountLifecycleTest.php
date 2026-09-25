<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B2bAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_admin_creates_pending_account_and_activates_it_with_audit(): void
    {
        $admin = $this->admin('B2B_ADMIN');
        $token = $admin->createToken('test')->plainTextToken;

        $created = $this->withToken($token)->postJson('/api/v1/admin/b2b/accounts', [
            'name'=>'Buyer','email'=>'buyer@example.test','password'=>'password123','company_name'=>'Buyer Co',
        ])->assertCreated()->assertJsonPath('data.status','pending');

        $id = $created->json('data.id');
        $this->assertDatabaseHas('users',['email'=>'buyer@example.test','is_active'=>false]);
        $this->assertDatabaseHas('audit_logs',['event'=>'b2b.account.created']);

        $this->withToken($token)->patchJson("/api/v1/admin/b2b/accounts/{$id}/status", ['status'=>'active'])
            ->assertOk()->assertJsonPath('data.status','active');
        $this->assertDatabaseHas('users',['email'=>'buyer@example.test','is_active'=>true]);
        $this->assertDatabaseHas('audit_logs',['event'=>'b2b.account.status_changed']);
    }

    public function test_denied_account_remains_inactive_and_unauthorized_role_is_forbidden(): void
    {
        $admin = $this->admin('B2B_ADMIN');
        $token = $admin->createToken('test')->plainTextToken;
        $created = $this->withToken($token)->postJson('/api/v1/admin/b2b/accounts', [
            'name'=>'Denied','email'=>'denied@example.test','password'=>'password123','company_name'=>'Denied Co',
        ])->assertCreated();
        $this->withToken($token)->patchJson('/api/v1/admin/b2b/accounts/'.$created->json('data.id').'/status',['status'=>'denied'])->assertOk();
        $this->assertDatabaseHas('users',['email'=>'denied@example.test','is_active'=>false]);

        $finance = $this->admin('FINANCE');
        $this->actingAs($finance, 'sanctum')->postJson('/api/v1/admin/b2b/accounts', [
            'name'=>'No','email'=>'no@example.test','password'=>'password123','company_name'=>'No Co',
        ])->assertForbidden();
    }

    public function test_public_b2b_registration_endpoint_does_not_exist(): void
    {
        $this->postJson('/api/v1/b2b/register', ['email'=>'public@example.test'])->assertNotFound();
    }

    private function admin(string $role): User
    {
        $user=User::query()->create(['name'=>$role,'email'=>strtolower($role).'@example.test','password'=>'password','is_active'=>true]);
        $user->roles()->attach(Role::query()->where('code',$role)->firstOrFail());
        return $user;
    }
}
