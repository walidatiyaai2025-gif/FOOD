<?php

namespace Tests\Feature;

use App\Domain\Assistant\Actions\AssistantActionApprovalService;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AssistantSafeActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_registry_exposes_only_the_governed_draft_action_mode(): void
    {
        $definition = app(AssistantToolRegistry::class)
            ->definition(AssistantActionApprovalService::ACTION_PURCHASE_REQUEST_DRAFT);

        $this->assertSame('DRAFT', $definition->mode);
        $this->assertSame(
            ['procurement.purchase_requests.manage'],
            $definition->requiredPermissions,
        );
        $this->assertSame(
            'AssistantActionApprovalService',
            $definition->source,
        );
    }

    public function test_draft_approval_execute_is_explicit_reauthorized_and_idempotent(): void
    {
        [$actor, $store, $role] = $this->scopedUser();
        config(['assistant.security.write_actions_enabled' => true]);

        $service = app(AssistantActionApprovalService::class);
        $approval = $service->create($actor, $this->input($store), 'en', 'action-safe-2224');

        $this->assertSame('draft', $approval->status);
        $this->assertDatabaseCount('enterprise_purchase_requests', 0);
        $this->assertSame('purchase_request', data_get($approval->preview, 'target.type'));
        $this->assertNotEmpty(data_get($approval->preview, 'side_effects.en'));

        try {
            $service->execute($actor, $approval);
            $this->fail('Execution without explicit approval must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $approved = $service->approve($actor, $approval, $approval->payload_hash, true);
        $this->assertSame('approved', $approved->status);

        $executed = $service->execute($actor, $approved);
        $retry = $service->execute($actor, $executed);

        $this->assertSame('executed', $retry->status);
        $this->assertSame($executed->result, $retry->result);
        $this->assertDatabaseCount('enterprise_purchase_requests', 1);
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => data_get($executed->result, 'entity_id'),
            'store_id' => $store->id,
            'status' => 'draft',
        ]);
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('event', 'assistant.action.executed')->count(),
        );

        $role->permissions()->detach(
            Permission::query()->where('code', 'procurement.purchase_requests.manage')->value('id'),
        );
    }

    public function test_payload_tampering_and_permission_loss_block_execution(): void
    {
        [$actor, $store, $role] = $this->scopedUser();
        config(['assistant.security.write_actions_enabled' => true]);

        $service = app(AssistantActionApprovalService::class);

        $tampered = $service->create($actor, $this->input($store), 'en');
        $tampered = $service->approve($actor, $tampered, $tampered->payload_hash, true);
        $payload = $tampered->payload;
        $payload['lines'][0]['quantity'] = 999;
        $tampered->forceFill(['payload' => $payload])->save();

        try {
            $service->execute($actor, $tampered->fresh());
            $this->fail('Tampered payload must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $freshInput = $this->input($store);
        $freshInput['payload']['reason'] = 'Permission-loss case';
        $permissionLoss = $service->create($actor, $freshInput, 'en');
        $permissionLoss = $service->approve($actor, $permissionLoss, $permissionLoss->payload_hash, true);

        $manage = Permission::query()->where('code', 'procurement.purchase_requests.manage')->firstOrFail();
        $role->permissions()->detach($manage->id);
        $actor->unsetRelation('roles');

        try {
            $service->execute($actor, $permissionLoss);
            $this->fail('Permission loss after approval must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('enterprise_purchase_requests', 0);
    }

    public function test_expired_approval_and_write_kill_switch_fail_closed(): void
    {
        [$actor, $store] = $this->scopedUser();
        $service = app(AssistantActionApprovalService::class);

        $approval = $service->create($actor, $this->input($store), 'ar');
        $approval->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            $service->approve($actor, $approval->fresh(), $approval->payload_hash, true);
            $this->fail('Expired approval must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $nextInput = $this->input($store);
        $nextInput['payload']['reason'] = 'Kill-switch case';
        $next = $service->create($actor, $nextInput, 'en');
        $next = $service->approve($actor, $next, $next->payload_hash, true);

        config(['assistant.security.write_actions_enabled' => false]);

        try {
            $service->execute($actor, $next);
            $this->fail('Write kill switch must block approved actions.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('enterprise_purchase_requests', 0);
    }

    /** @return array<string,mixed> */
    private function input(Store $store): array
    {
        return [
            'action_key' => AssistantActionApprovalService::ACTION_PURCHASE_REQUEST_DRAFT,
            'store_id' => $store->id,
            'channel' => 'b2c',
            'payload' => [
                'currency_code' => 'KWD',
                'reason' => 'Assistant governed replenishment proposal',
                'lines' => [[
                    'item_code' => 'AI-ITEM-1',
                    'description' => 'Governed replenishment item',
                    'quantity' => 4,
                    'unit' => 'case',
                    'estimated_unit_cost' => 2.500,
                ]],
            ],
        ];
    }

    /** @return array{User,Store,Role} */
    private function scopedUser(): array
    {
        foreach (['assistant.use', 'procurement.purchase_requests.manage'] as $permissionCode) {
            Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode],
            );
        }

        $role = Role::query()->create([
            'code' => 'AI_SAFE_ACTION_OPERATOR',
            'name' => 'AI Safe Action Operator',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()
                ->whereIn('code', ['assistant.use', 'procurement.purchase_requests.manage'])
                ->pluck('id')
                ->all(),
        );

        $actor = User::factory()->create(['is_active' => true]);
        $store = $this->createRetailStore('AI-ACTION-STORE');

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store, $role];
    }

    private function createRetailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId < 1) {
            $typeId = DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
