<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2AuditExplorerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_admin_hub_exposes_a_real_authorized_audit_tab_in_both_locales(): void
    {
        $owner = $this->actor('SUPER_ADMIN', 'en');

        $this->actingAs($owner)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('data-security-audit-entry', false)
            ->assertSee(route('admin.security.index', ['section' => 'audit']), false);

        $this->actingAs($owner)
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-security-audit-explorer', false)
            ->assertSee('aria-label="'.__('security_governance.audit.actor').'"', false);

        $owner->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($owner->fresh())
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('data-security-audit-explorer', false)
            ->assertSee('aria-label="'.__('security_governance.audit.actor').'"', false);
    }

    public function test_authorized_security_admin_can_filter_audit_records_and_see_bounded_results(): void
    {
        $securityAdmin = $this->actor('SECURITY_ADMINISTRATOR');

        for ($number = 0; $number < 28; $number++) {
            AuditLog::query()->create([
                'user_id' => $securityAdmin->id,
                'event' => 'v2.audit.list.'.$number,
                'auditable_type' => 'Order',
                'auditable_id' => $number + 1,
                'ip_address' => '127.0.0.1',
            ]);
        }

        AuditLog::query()->create([
            'user_id' => $securityAdmin->id,
            'event' => 'v2.audit.secret.other',
            'auditable_type' => 'Store',
        ]);

        $this->actingAs($securityAdmin)
            ->get(route('admin.security.index', [
                'section' => 'audit',
                'audit_event' => 'v2.audit.list.',
            ]))
            ->assertOk()
            ->assertSee('v2.audit.list.')
            ->assertDontSee('v2.audit.secret.other')
            ->assertViewHas('auditRows', fn ($rows): bool => $rows->perPage() === 25
                && $rows->count() === 25
                && $rows->total() === 28);
    }

    public function test_wholesale_and_store_scoped_actors_cannot_read_other_store_audit_records(): void
    {
        $wholesale = $this->actor('B2B_ADMIN');
        $retail = $this->actor();
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeA = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-AUDIT-A',
            'name' => 'V2 Audit Store A',
            'is_active' => true,
        ]);
        $storeB = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-AUDIT-B',
            'name' => 'V2 Audit Store B',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeA->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AuditLog::query()->create([
            'event' => 'v2.audit.private.cross_store',
            'auditable_type' => Store::class,
            'auditable_id' => $storeB->id,
        ]);

        foreach ([$wholesale, $retail] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.security.index', ['section' => 'audit']))
                ->assertForbidden();
        }
    }

    public function test_effective_global_security_permission_controls_multi_role_access(): void
    {
        $actor = $this->actor('B2B_ADMIN');
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail();
        $actor->roles()->attach($role->id);

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertOk();

        $actor->roles()->detach($role->id);

        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertForbidden();
    }

    public function test_core_audit_access_remains_authoritative_when_optional_modules_are_disabled(): void
    {
        $modules = app(ModuleRegistry::class);
        $registration = $modules->syncManifest('smart_ordering');
        $registration->forceFill(['state' => ModuleState::DISABLED->value])->save();

        $this->assertFalse($modules->isEnabled('smart_ordering'));

        $this->actingAs($this->actor('SECURITY_ADMINISTRATOR'))
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertOk();

        $this->actingAs($this->actor('B2B_ADMIN'))
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertForbidden();
    }

    private function actor(?string $roleCode = null, string $locale = 'en'): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => $locale,
        ]);

        if ($roleCode !== null) {
            $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail()->id);
        }

        return $user;
    }
}
