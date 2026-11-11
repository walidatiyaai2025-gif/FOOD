<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RbacManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2UserRestoreSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archive_and_restore_are_rejected_until_reason_impact_and_email_are_confirmed(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $target = $this->userWithRole('B2B_ADMIN');

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), ['is_active' => false])
            ->assertSessionHasErrors('reason');
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Archive only after review',
                'confirm_email' => $target->email,
            ])->assertSessionHasErrors('impact_reviewed');
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Archive only after review',
                'impact_reviewed' => '1',
                'confirm_email' => 'incorrect@example.test',
            ])->assertSessionHasErrors('confirm_email');
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), $this->reviewedStatus($target, false, 'Archive with signed impact review'))
            ->assertRedirect();
        $this->assertFalse((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), ['is_active' => true])
            ->assertSessionHasErrors('reason');
        $this->assertFalse((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), $this->reviewedStatus($target, true, 'Restore after current entitlement review'))
            ->assertRedirect();
        $this->assertTrue((bool) $target->fresh()->is_active);

        $events = AuditLog::query()
            ->where('user_id', $admin->id)
            ->where('auditable_id', $target->id)
            ->where('event', 'user.status_changed')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $events);
        $this->assertSame('Archive with signed impact review', $events[0]->after['change_reason']);
        $this->assertSame('Restore after current entitlement review', $events[1]->after['change_reason']);
        $this->assertTrue($events[1]->after['impact_reviewed']);
    }

    public function test_admin_api_requires_same_server_side_confirmations(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $target = $this->userWithRole('B2B_ADMIN');
        $url = '/api/v1/admin/security/users/'.$target->id.'/status';

        Sanctum::actingAs($admin);

        $this->patchJson($url, ['is_active' => false, 'reason' => 'API archive without approval'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('impact_reviewed');
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->patchJson($url, $this->reviewedStatus($target, false, 'API archive with full review'))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_failed_audit_rolls_back_archival_and_all_session_revocations(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $target = $this->userWithRole('B2B_ADMIN');
        $token = $target->createToken('preserved-when-audit-fails');

        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')
            ->once()
            ->andThrow(new \RuntimeException('audit persistence failed'));
        $this->app->instance(AuditLogger::class, $audit);

        try {
            app(RbacManager::class)->setUserActive(
                $admin,
                $target,
                false,
                'Archived after impact review',
                Request::create('/admin/security', 'PATCH', [
                    'impact_reviewed' => true,
                    'confirm_email' => $target->email,
                ]),
            );
            $this->fail('An account may not be archived without a committed audit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit persistence failed', $exception->getMessage());
        }

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertNull($target->fresh()->deactivated_at);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_id' => $target->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewedStatus(User $target, bool $active, string $reason): array
    {
        return [
            'is_active' => $active,
            'reason' => $reason,
            'impact_reviewed' => true,
            'confirm_email' => $target->email,
        ];
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $user;
    }
}
