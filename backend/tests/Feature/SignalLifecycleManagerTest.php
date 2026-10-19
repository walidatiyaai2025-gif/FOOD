<?php

namespace Tests\Feature;

use App\Domain\Analytics\Signals\SignalLifecycleManager;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SignalLifecycleManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_signal_lifecycle_transitions_are_audited_and_reproducible(): void
    {
        $storeId = $this->store('AI-SIGNAL-LIFE-A');
        $actor = $this->storeUser($storeId, 'B2C_STORE_ADMIN');
        $manager = app(SignalLifecycleManager::class);
        $signal = $this->signal($storeId);

        $acknowledged = $manager->transition(
            $actor,
            $signal,
            'acknowledged',
            'Manager reviewed the evidence.',
            '2026-10-18T20:00:00+00:00',
        );

        $this->assertSame('acknowledged', $acknowledged['lifecycle']['state']);
        $this->assertSame(['resolved', 'suppressed'], $acknowledged['lifecycle']['allowed_next']);
        $this->assertSame((int) $actor->getKey(), $acknowledged['lifecycle']['changed_by_user_id']);
        $this->assertSame('new', $acknowledged['audit_trail'][0]['from']);
        $this->assertSame('acknowledged', $acknowledged['audit_trail'][0]['to']);

        $resolved = $manager->transition(
            $actor,
            $acknowledged,
            'resolved',
            'Underlying condition returned to baseline.',
            '2026-10-18T21:00:00+00:00',
        );

        $this->assertSame('resolved', $resolved['lifecycle']['state']);
        $this->assertSame(['new'], $resolved['lifecycle']['allowed_next']);
        $this->assertCount(2, $resolved['audit_trail']);

        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'assistant.signal.lifecycle',
            'user_id' => $actor->getKey(),
            'store_id' => $storeId,
        ]);
    }

    public function test_suppression_requires_reason_and_invalid_transition_is_rejected(): void
    {
        $storeId = $this->store('AI-SIGNAL-LIFE-B');
        $actor = $this->storeUser($storeId, 'B2C_STORE_ADMIN');
        $manager = app(SignalLifecycleManager::class);
        $signal = $this->signal($storeId);

        try {
            $manager->transition($actor, $signal, 'suppressed');
            $this->fail('Suppression without a reason must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        try {
            $manager->transition($actor, $signal, 'new', 'Invalid no-op.');
            $this->fail('Invalid lifecycle transition must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('state', $exception->errors());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_actor_cannot_mutate_signal_from_another_tenant_scope(): void
    {
        $storeA = $this->store('AI-SIGNAL-SCOPE-A');
        $storeB = $this->store('AI-SIGNAL-SCOPE-B');
        $actor = $this->storeUser($storeA, 'B2C_STORE_ADMIN');

        try {
            app(SignalLifecycleManager::class)->transition(
                $actor,
                $this->signal($storeB),
                'acknowledged',
                'Should never be accepted.',
            );
            $this->fail('Cross-tenant signal lifecycle mutation must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function store(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeUser(int $storeId, string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.$storeId.'-'.uniqid().'@signal.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @return array<string,mixed> */
    private function signal(int $storeId): array
    {
        return [
            'fingerprint' => hash('sha256', 'signal-'.$storeId),
            'code' => 'revenue_drop',
            'kind' => 'risk',
            'severity' => 'high',
            'scope' => [
                'channel' => 'b2c',
                'flow' => 'sell_out',
                'store_ids' => [$storeId],
            ],
            'lifecycle' => [
                'state' => 'new',
                'allowed_next' => ['acknowledged', 'resolved', 'suppressed'],
            ],
        ];
    }
}
